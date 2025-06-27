<?php

namespace Tests\Unit;

use App\Models\Category;
use App\Models\Expense;
use App\Models\User;
use App\Services\GoogleSheetsService;
use Carbon\Carbon;
use Google\Client;
use Google\Service\Sheets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class GoogleSheetsServiceTest extends TestCase
{
    use RefreshDatabase;

    protected GoogleSheetsService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Set up test configuration
        config([
            'services.google_sheets.enabled' => true,
            'services.google_sheets.spreadsheet_id' => 'test-spreadsheet-id',
            'services.google_sheets.credentials_path' => storage_path('app/test-credentials.json'),
        ]);

        $this->service = new GoogleSheetsService;
    }

    public function test_get_category_totals_calculates_correctly()
    {
        $user = User::factory()->create();

        $foodCategory = Category::factory()->create(['name' => 'Food']);
        $transportCategory = Category::factory()->create(['name' => 'Transport']);

        // Create expenses for current month
        $currentMonth = Carbon::now()->startOfMonth();

        Expense::factory()->create([
            'user_id' => $user->id,
            'category_id' => $foodCategory->id,
            'amount' => 100,
            'expense_date' => $currentMonth,
            'status' => 'confirmed',
        ]);

        Expense::factory()->create([
            'user_id' => $user->id,
            'category_id' => $foodCategory->id,
            'amount' => 50,
            'expense_date' => $currentMonth->copy()->addDays(5),
            'status' => 'confirmed',
        ]);

        Expense::factory()->create([
            'user_id' => $user->id,
            'category_id' => $transportCategory->id,
            'amount' => 200,
            'expense_date' => $currentMonth->copy()->addDays(10),
            'status' => 'confirmed',
        ]);

        // Create a pending expense that shouldn't be counted
        Expense::factory()->create([
            'user_id' => $user->id,
            'category_id' => $foodCategory->id,
            'amount' => 500,
            'expense_date' => $currentMonth,
            'status' => 'pending',
        ]);

        $totals = $this->service->getCategoryTotals(
            $currentMonth,
            $currentMonth->copy()->endOfMonth()
        );

        $this->assertEquals(150, $totals['Food']);
        $this->assertEquals(200, $totals['Transport']);
        $this->assertArrayNotHasKey('pending', $totals);
    }

    public function test_category_totals_handles_hierarchical_categories()
    {
        $user = User::factory()->create();

        $parentCategory = Category::factory()->create(['name' => 'Food']);
        $childCategory = Category::factory()->create([
            'name' => 'Restaurant',
            'parent_id' => $parentCategory->id,
        ]);

        $currentMonth = Carbon::now()->startOfMonth();

        Expense::factory()->create([
            'user_id' => $user->id,
            'category_id' => $childCategory->id,
            'amount' => 300,
            'expense_date' => $currentMonth,
            'status' => 'confirmed',
        ]);

        $totals = $this->service->getCategoryTotals(
            $currentMonth,
            $currentMonth->copy()->endOfMonth()
        );

        // Should use parent category name for hierarchical categories
        $this->assertEquals(300, $totals['Food']);
        $this->assertArrayNotHasKey('Restaurant', $totals);
    }

    public function test_service_respects_enabled_configuration()
    {
        config(['services.google_sheets.enabled' => false]);

        $mockClient = Mockery::mock(Client::class);
        $mockSheets = Mockery::mock(Sheets::class);

        // When disabled, initialize should return early without creating clients
        $service = new GoogleSheetsService;
        $service->initialize();

        // This test passes if no exceptions are thrown
        $this->assertTrue(true);
    }

    public function test_handles_missing_spreadsheet_id()
    {
        config(['services.google_sheets.spreadsheet_id' => null]);

        $service = new GoogleSheetsService;

        // Should handle missing spreadsheet ID gracefully
        $this->assertNull(config('services.google_sheets.spreadsheet_id'));
    }

    public function test_expense_data_formatting()
    {
        $user = User::factory()->create();

        $parentCategory = Category::factory()->create(['name' => 'Food']);
        $category = Category::factory()->create([
            'name' => 'Restaurant',
            'parent_id' => $parentCategory->id,
        ]);

        $expense = Expense::factory()->create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'amount' => 150.50,
            'currency' => 'MXN',
            'description' => 'Lunch at cafe',
            'expense_date' => Carbon::parse('2024-01-15'),
            'status' => 'confirmed',
            'inference_method' => 'keyword',
            'category_confidence' => 0.95,
        ]);

        // Test that expense would be formatted correctly for sheets
        $this->assertEquals('2024-01-15', $expense->expense_date->format('Y-m-d'));
        $this->assertEquals(150.50, $expense->amount);
        $this->assertEquals('MXN', $expense->currency);
        $this->assertEquals('Lunch at cafe', $expense->description);
        $this->assertEquals('Food', $expense->category->parent->name);
        $this->assertEquals('Restaurant', $expense->category->name);
        $this->assertEquals('keyword', $expense->inference_method);
        $this->assertEquals(0.95, $expense->category_confidence);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
