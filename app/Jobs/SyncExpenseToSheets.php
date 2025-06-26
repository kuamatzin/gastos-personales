<?php

namespace App\Jobs;

use App\Models\Expense;
use App\Services\GoogleSheetsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Exception;

class SyncExpenseToSheets implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 5;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var array<int>
     */
    public $backoff = [60, 120, 300];

    /**
     * The expense instance.
     *
     * @var Expense
     */
    protected $expense;

    /**
     * Create a new job instance.
     */
    public function __construct(Expense $expense)
    {
        $this->expense = $expense;
    }

    /**
     * Execute the job.
     */
    public function handle(GoogleSheetsService $sheets): void
    {
        try {
            // Check if Google Sheets is enabled
            if (!config('services.google_sheets.enabled')) {
                Log::info('Google Sheets sync skipped - feature disabled');
                return;
            }

            // Log start of sync
            Log::info('Syncing expense to Google Sheets', [
                'expense_id' => $this->expense->id,
                'amount' => $this->expense->amount,
                'category' => $this->expense->category->name ?? 'Unknown'
            ]);

            // Sync the expense
            $sheets->appendExpense($this->expense);

            // Log successful sync
            Log::info('Expense synced to Google Sheets successfully', [
                'expense_id' => $this->expense->id
            ]);

        } catch (Exception $e) {
            Log::error('Failed to sync expense to Google Sheets', [
                'expense_id' => $this->expense->id,
                'error' => $e->getMessage(),
                'attempt' => $this->attempts()
            ]);

            // Check if this is a quota exceeded error
            if (strpos($e->getMessage(), 'quota') !== false || 
                strpos($e->getMessage(), 'rate limit') !== false) {
                // Release the job back to the queue with a longer delay
                $this->release(600); // 10 minutes
                return;
            }

            // Re-throw the exception to trigger retry logic
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(Exception $exception): void
    {
        Log::error('Google Sheets sync job failed permanently', [
            'expense_id' => $this->expense->id,
            'error' => $exception->getMessage()
        ]);
    }

    /**
     * Determine the number of seconds until the job should timeout.
     */
    public function retryUntil(): \DateTime
    {
        return now()->addHours(24);
    }
}