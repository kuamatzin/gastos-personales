<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Subscription;
use App\Services\TelegramService;
use Illuminate\Support\Facades\Log;

class ProcessDueSubscriptions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'subscriptions:process-due';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process due subscription charges';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Processing due subscriptions...');
        
        $dueSubscriptions = Subscription::where('status', 'active')
            ->where(function ($query) {
                $query->whereDate('next_charge_date', '<=', now())
                      ->orWhereNull('next_charge_date');
            })
            ->get();
        
        $this->info("Found {$dueSubscriptions->count()} due subscriptions");
        
        $processed = 0;
        $failed = 0;
        
        foreach ($dueSubscriptions as $subscription) {
            try {
                $this->info("Processing subscription #{$subscription->id}: {$subscription->name}");
                
                // Create the expense
                $expense = $subscription->createExpense();
                
                if ($expense) {
                    $processed++;
                    $this->info("✓ Created expense #{$expense->id} for subscription #{$subscription->id}");
                    
                    // Send notification to user
                    $this->notifyUser($subscription, $expense);
                } else {
                    $this->warn("⚠ Subscription #{$subscription->id} is not due yet");
                }
                
            } catch (\Exception $e) {
                $failed++;
                $this->error("✗ Failed to process subscription #{$subscription->id}: {$e->getMessage()}");
                
                Log::error('Failed to process subscription', [
                    'subscription_id' => $subscription->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }
        
        $this->info("\nProcessing complete!");
        $this->info("Processed: {$processed} subscriptions");
        if ($failed > 0) {
            $this->warn("Failed: {$failed} subscriptions");
        }
        
        return Command::SUCCESS;
    }
    
    /**
     * Notify user about the subscription charge
     */
    private function notifyUser(Subscription $subscription, $expense): void
    {
        try {
            $telegram = app(TelegramService::class);
            $user = $subscription->user;
            
            if (!$user->telegram_id) {
                Log::warning('Cannot notify user without telegram_id', [
                    'user_id' => $user->id,
                    'subscription_id' => $subscription->id,
                ]);
                return;
            }
            
            $periodicityText = $subscription->getPeriodicityText($user->language ?? 'es');
            
            $message = trans('telegram.subscription_payment_processed', [
                'name' => $subscription->name,
                'amount' => number_format($subscription->amount, 2),
                'currency' => $subscription->currency,
                'periodicity' => $periodicityText,
                'next_charge' => $subscription->next_charge_date->format('d/m/Y'),
            ], $user->language ?? 'es');
            
            $telegram->sendMessage($user->telegram_id, $message);
            
        } catch (\Exception $e) {
            Log::error('Failed to notify user about subscription charge', [
                'subscription_id' => $subscription->id,
                'expense_id' => $expense->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}