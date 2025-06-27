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

class SyncExpenseToGoogleSheets implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The expense to sync
     */
    protected Expense $expense;

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
    public function handle(GoogleSheetsService $googleSheets): void
    {
        try {
            // Only sync confirmed expenses
            if ($this->expense->status !== 'confirmed') {
                Log::info('Skipping Google Sheets sync for non-confirmed expense', [
                    'expense_id' => $this->expense->id,
                    'status' => $this->expense->status
                ]);
                return;
            }

            // Initialize Google Sheets service
            $googleSheets->initialize();
            
            // Append the expense
            $googleSheets->appendExpense($this->expense);
            
            Log::info('Expense synced to Google Sheets', [
                'expense_id' => $this->expense->id,
                'amount' => $this->expense->amount,
                'category' => $this->expense->category->name
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to sync expense to Google Sheets', [
                'expense_id' => $this->expense->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Re-throw to trigger retry mechanism
            throw $e;
        }
    }

    /**
     * Determine the time at which the job should timeout.
     */
    public function retryUntil(): \DateTime
    {
        return now()->addMinutes(5);
    }
}