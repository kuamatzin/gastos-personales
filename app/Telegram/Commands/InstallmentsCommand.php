<?php

namespace App\Telegram\Commands;

use App\Models\User;
use App\Services\TelegramService;
use Illuminate\Support\Facades\Log;

class InstallmentsCommand extends Command
{
    protected string $command = 'installments';
    
    protected string $description = 'View your active installment plans';

    public function handle(array $message, User $user): bool
    {
        $telegramService = app(TelegramService::class);
        $chatId = $message['chat']['id'];
        
        try {
            // Get active installment plans
            $activePlans = $user->installmentPlans()
                ->active()
                ->with('category')
                ->orderBy('next_payment_date')
                ->get();
            
            if ($activePlans->isEmpty()) {
                $telegramService->sendMessage(
                    $chatId,
                    trans('telegram.installment_list_empty', [], $user->language),
                    ['parse_mode' => 'Markdown']
                );
                return true;
            }
            
            // Build message with active plans
            $message = trans('telegram.installment_list_header', [], $user->language) . "\n\n";
            
            foreach ($activePlans as $plan) {
                $progress = $plan->getNextInstallmentNumber() - 1;
                $message .= trans('telegram.installment_detail', [
                    'description' => $plan->description,
                    'monthly' => number_format($plan->monthly_amount, 2),
                    'months' => $plan->total_months,
                    'date' => $plan->next_payment_date->format('d/m/Y'),
                ], $user->language) . "\n";
                
                // Add progress bar
                $progressBar = $this->createProgressBar($progress, $plan->total_months);
                $message .= "  " . $progressBar . " {$progress}/{$plan->total_months}\n\n";
            }
            
            // Add summary
            $totalMonthly = $activePlans->sum('monthly_amount');
            $message .= "\n💰 " . trans('telegram.total_monthly_payments', [
                'amount' => number_format($totalMonthly, 2)
            ], $user->language);
            
            $telegramService->sendMessage(
                $chatId,
                $message,
                ['parse_mode' => 'Markdown']
            );
            
            Log::info('Installments command executed', [
                'user_id' => $user->id,
                'active_plans' => $activePlans->count(),
            ]);
            
            return true;
            
        } catch (\Exception $e) {
            Log::error('Failed to list installments', [
                'error' => $e->getMessage(),
                'user_id' => $user->id,
            ]);
            
            $telegramService->sendMessage(
                $chatId,
                trans('telegram.error_occurred', [], $user->language)
            );
            
            return true;
        }
    }
    
    /**
     * Create a visual progress bar
     */
    private function createProgressBar(int $current, int $total): string
    {
        $percentage = ($current / $total) * 100;
        $filled = round($percentage / 10); // 10 segments
        $empty = 10 - $filled;
        
        $bar = str_repeat('▓', $filled) . str_repeat('░', $empty);
        
        return "[{$bar}]";
    }
}