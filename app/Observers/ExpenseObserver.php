<?php

namespace App\Observers;

use App\Models\Expense;
use App\Jobs\SyncExpenseToSheets;
use Illuminate\Support\Facades\Log;

class ExpenseObserver
{
    /**
     * Handle the Expense "created" event.
     */
    public function created(Expense $expense): void
    {
        // Only sync confirmed expenses
        if ($expense->status === 'confirmed') {
            $this->syncToSheets($expense);
        }
    }

    /**
     * Handle the Expense "updated" event.
     */
    public function updated(Expense $expense): void
    {
        // Only sync if status changed from non-confirmed to confirmed
        if ($expense->status === 'confirmed' && 
            $expense->wasChanged('status') && 
            $expense->getOriginal('status') !== 'confirmed') {
            $this->syncToSheets($expense);
        }
    }

    /**
     * Handle the Expense "deleted" event.
     */
    public function deleted(Expense $expense): void
    {
        // Log deletion for audit purposes
        Log::info('Expense deleted', [
            'expense_id' => $expense->id,
            'amount' => $expense->amount,
            'category' => $expense->category->name ?? 'Unknown'
        ]);
        
        // Note: We don't remove from sheets to maintain historical records
        // You might want to implement a soft delete column in sheets instead
    }

    /**
     * Handle the Expense "restored" event.
     */
    public function restored(Expense $expense): void
    {
        // If using soft deletes, sync restored expense
        if ($expense->status === 'confirmed') {
            $this->syncToSheets($expense);
        }
    }

    /**
     * Handle the Expense "force deleted" event.
     */
    public function forceDeleted(Expense $expense): void
    {
        // Log force deletion
        Log::warning('Expense force deleted', [
            'expense_id' => $expense->id,
            'amount' => $expense->amount
        ]);
    }

    /**
     * Dispatch sync job to Google Sheets
     */
    private function syncToSheets(Expense $expense): void
    {
        try {
            // Check if Google Sheets sync is enabled
            if (config('services.google_sheets.enabled')) {
                SyncExpenseToSheets::dispatch($expense)
                    ->onQueue('default')
                    ->delay(now()->addSeconds(2)); // Small delay to ensure all data is committed
                
                Log::info('Google Sheets sync job dispatched', [
                    'expense_id' => $expense->id
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to dispatch Google Sheets sync job', [
                'expense_id' => $expense->id,
                'error' => $e->getMessage()
            ]);
        }
    }
}