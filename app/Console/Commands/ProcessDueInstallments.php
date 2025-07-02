<?php

namespace App\Console\Commands;

use App\Models\InstallmentPlan;
use App\Services\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class ProcessDueInstallments extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'installments:process-due';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process due installment payments and create expense records';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Processing due installments...');

        // Get all active plans with payments due
        $duePlans = InstallmentPlan::paymentDue()
            ->with('user')
            ->get();

        $processed = 0;
        $errors = 0;

        foreach ($duePlans as $plan) {
            try {
                // Create the next expense
                $expense = $plan->createNextExpense();
                
                if ($expense) {
                    $processed++;
                    
                    // Notify user via Telegram
                    $this->notifyUser($plan, $expense);
                    
                    $this->info("Processed installment for plan #{$plan->id}: {$plan->description}");
                    
                    Log::info('Installment payment processed', [
                        'plan_id' => $plan->id,
                        'expense_id' => $expense->id,
                        'user_id' => $plan->user_id,
                        'amount' => $expense->amount,
                        'installment_number' => $expense->installment_number,
                    ]);
                }
            } catch (\Exception $e) {
                $errors++;
                
                $this->error("Failed to process plan #{$plan->id}: {$e->getMessage()}");
                
                Log::error('Failed to process installment', [
                    'plan_id' => $plan->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Completed. Processed: {$processed}, Errors: {$errors}");

        return self::SUCCESS;
    }

    /**
     * Notify user about the processed installment payment
     */
    private function notifyUser(InstallmentPlan $plan, $expense): void
    {
        try {
            $telegram = app(TelegramService::class);
            $user = $plan->user;
            
            // Build notification message
            $message = trans('telegram.installment_payment_processed', [
                'amount' => number_format($expense->amount, 2)
            ], $user->language) . "\n\n";
            
            $message .= "📦 {$expense->description}\n";
            
            // Add progress info
            $progress = $plan->total_months - $plan->remaining_months;
            $message .= "📊 Pago {$progress} de {$plan->total_months}\n";
            
            // Add next payment date if not completed
            if (!$plan->isCompleted()) {
                $nextDate = Carbon::parse($plan->next_payment_date)->locale($user->language ?? 'es');
                $message .= trans('telegram.installment_next_payment', [
                    'date' => $nextDate->format('d/m/Y')
                ], $user->language);
            } else {
                $message .= "\n" . trans('telegram.installment_completed', [], $user->language);
            }
            
            $telegram->sendMessage(
                $user->telegram_id,
                $message,
                ['parse_mode' => 'Markdown']
            );
            
        } catch (\Exception $e) {
            Log::error('Failed to notify user about installment payment', [
                'plan_id' => $plan->id,
                'user_id' => $plan->user_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}