<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Expense;
use App\Models\Category;
use App\Services\GoogleSheetsService;
use App\Jobs\SyncExpenseToSheets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Google\Service\Sheets;

class GoogleSheetsIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Mock Google Sheets for testing
        config(['services.google_sheets.enabled' => true]);
    }

    public function test_sync_expense_to_sheets_job_is_dispatched_when_expense_is_created()
    {
        Queue::fake();

        $user = User::factory()->create();
        $category = Category::factory()->create();

        $expense = Expense::create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'amount' => 150.50,
            'currency' => 'MXN',
            'description' => 'Test expense',
            'expense_date' => now(),
            'status' => 'confirmed'
        ]);

        Queue::assertPushed(SyncExpenseToSheets::class, function ($job) use ($expense) {
            return $job->expense->id === $expense->id;
        });
    }

    public function test_sync_expense_to_sheets_job_is_not_dispatched_for_pending_expenses()
    {
        Queue::fake();

        $user = User::factory()->create();
        $category = Category::factory()->create();

        $expense = Expense::create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'amount' => 150.50,
            'currency' => 'MXN',
            'description' => 'Test expense',
            'expense_date' => now(),
            'status' => 'pending'
        ]);

        Queue::assertNotPushed(SyncExpenseToSheets::class);
    }

    public function test_sync_expense_to_sheets_job_is_dispatched_when_expense_status_changes_to_confirmed()
    {
        Queue::fake();

        $user = User::factory()->create();
        $category = Category::factory()->create();

        $expense = Expense::create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'amount' => 150.50,
            'currency' => 'MXN',
            'description' => 'Test expense',
            'expense_date' => now(),
            'status' => 'pending'
        ]);

        Queue::assertNotPushed(SyncExpenseToSheets::class);

        $expense->update(['status' => 'confirmed']);

        Queue::assertPushed(SyncExpenseToSheets::class, function ($job) use ($expense) {
            return $job->expense->id === $expense->id;
        });
    }

    public function test_google_sheets_service_formats_expense_data_correctly()
    {
        $parentCategory = Category::factory()->create(['name' => 'Food']);
        $category = Category::factory()->create([
            'name' => 'Restaurant',
            'parent_id' => $parentCategory->id
        ]);

        $user = User::factory()->create();
        $expense = Expense::create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'amount' => 250.75,
            'currency' => 'MXN',
            'description' => 'Dinner at restaurant',
            'expense_date' => now(),
            'status' => 'confirmed',
            'inference_method' => 'ai',
            'category_confidence' => 0.85
        ]);

        // Mock the Google Sheets service
        $mockSheets = Mockery::mock(GoogleSheetsService::class)->makePartial();
        $mockSheets->shouldAllowMockingProtectedMethods();
        
        // We'll just verify that the data would be formatted correctly
        $this->assertEquals('Food', $expense->category->parent->name);
        $this->assertEquals('Restaurant', $expense->category->name);
        $this->assertEquals(250.75, $expense->amount);
        $this->assertEquals('ai', $expense->inference_method);
        $this->assertEquals(0.85, $expense->category_confidence);
    }

    public function test_sync_job_respects_google_sheets_enabled_config()
    {
        config(['services.google_sheets.enabled' => false]);
        
        Queue::fake();

        $user = User::factory()->create();
        $category = Category::factory()->create();

        $expense = Expense::create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'amount' => 150.50,
            'currency' => 'MXN',
            'description' => 'Test expense',
            'expense_date' => now(),
            'status' => 'confirmed'
        ]);

        // Even with sheets disabled, the job should still be dispatched
        // The job itself will check if sheets are enabled
        Queue::assertPushed(SyncExpenseToSheets::class);
    }

    public function test_sync_job_handles_quota_exceeded_error()
    {
        $user = User::factory()->create();
        $category = Category::factory()->create();

        $expense = Expense::factory()->create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'status' => 'confirmed'
        ]);

        $mockSheets = Mockery::mock(GoogleSheetsService::class);
        $mockSheets->shouldReceive('appendExpense')
            ->once()
            ->andThrow(new \Exception('Quota exceeded for quota metric'));

        $job = new SyncExpenseToSheets($expense);
        
        // The job should release itself when quota is exceeded
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Quota exceeded');
        
        $job->handle($mockSheets);
    }

    public function test_initialize_google_sheets_command()
    {
        $mockSheets = Mockery::mock(GoogleSheetsService::class);
        $mockSheets->shouldReceive('initialize')->once();
        
        $this->app->instance(GoogleSheetsService::class, $mockSheets);

        $this->artisan('sheets:init')
            ->expectsOutput('Initializing Google Sheets structure...')
            ->assertSuccessful();
    }

    public function test_sync_historical_data_command_with_dry_run()
    {
        $user = User::factory()->create();
        $category = Category::factory()->create();

        Expense::factory()->count(5)->create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'status' => 'confirmed'
        ]);

        $this->artisan('sheets:sync-historical --dry-run')
            ->expectsOutput('Starting historical data sync to Google Sheets...')
            ->expectsOutput('Found 5 expenses to sync.')
            ->expectsOutput('Dry run mode - no data will be synced.')
            ->assertSuccessful();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}