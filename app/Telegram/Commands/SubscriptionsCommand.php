<?php

namespace App\Telegram\Commands;

use App\Models\Subscription;
use Illuminate\Support\Facades\Log;

class SubscriptionsCommand extends Command
{
    protected string $name = 'subscriptions';

    public function handle(array $message, string $params = ''): void
    {
        $chatId = $message['chat']['id'];
        $user = $this->user;
        $language = $user->language ?? 'es';

        try {
            // Get active subscriptions
            $subscriptions = $user->subscriptions()
                ->whereIn('status', ['active', 'paused'])
                ->orderBy('status')
                ->orderBy('next_charge_date')
                ->get();

            if ($subscriptions->isEmpty()) {
                $this->telegram->sendMessage(
                    $chatId,
                    trans('telegram.no_subscriptions', [], $language)
                );
                return;
            }

            // Send each subscription as a separate message with management buttons
            $this->telegram->sendMessage(
                $chatId,
                trans('telegram.subscriptions_title', [], $language),
                ['parse_mode' => 'Markdown']
            );
            
            $totalMonthly = 0;
            
            foreach ($subscriptions as $subscription) {
                $periodicityText = $subscription->getPeriodicityText($language);
                
                // Build subscription message
                $subMessage = "📦 *{$subscription->name}*\n";
                $subMessage .= "💵 $" . number_format($subscription->amount, 2) . " {$subscription->currency} - {$periodicityText}\n";
                
                if ($subscription->status === 'paused') {
                    $subMessage .= "⏸️ " . trans('telegram.subscription_paused', [], $language) . "\n";
                } else {
                    $subMessage .= "📆 " . trans('telegram.next_charge', ['date' => $subscription->next_charge_date->format('d/m/Y')], $language) . "\n";
                }
                
                if ($subscription->category) {
                    $subMessage .= "🏷️ {$subscription->category->getTranslatedName($language)}\n";
                }
                
                // Create management keyboard
                $keyboard = [];
                
                if ($subscription->status === 'active') {
                    $keyboard[] = [
                        [
                            'text' => trans('telegram.button_pause_subscription', [], $language),
                            'callback_data' => "sub_pause_{$subscription->id}",
                        ],
                        [
                            'text' => trans('telegram.button_cancel_subscription', [], $language),
                            'callback_data' => "sub_cancel_{$subscription->id}",
                        ],
                    ];
                } else {
                    $keyboard[] = [
                        [
                            'text' => trans('telegram.button_resume_subscription', [], $language),
                            'callback_data' => "sub_resume_{$subscription->id}",
                        ],
                        [
                            'text' => trans('telegram.button_cancel_subscription', [], $language),
                            'callback_data' => "sub_cancel_{$subscription->id}",
                        ],
                    ];
                }
                
                $this->telegram->sendMessageWithKeyboard($chatId, $subMessage, $keyboard, [
                    'parse_mode' => 'Markdown',
                ]);
                
                // Calculate monthly equivalent (only for active subscriptions)
                if ($subscription->status === 'active') {
                    $monthlyAmount = match($subscription->periodicity) {
                        'daily' => $subscription->amount * 30,
                        'weekly' => $subscription->amount * 4.33,
                        'biweekly' => $subscription->amount * 2.17,
                        'monthly' => $subscription->amount,
                        'quarterly' => $subscription->amount / 3,
                        'yearly' => $subscription->amount / 12,
                        default => 0,
                    };
                    
                    $totalMonthly += $monthlyAmount;
                }
            }
            
            if ($totalMonthly > 0) {
                $summaryMessage = "\n💰 ".trans('telegram.total_monthly_subscriptions', [
                    'amount' => number_format($totalMonthly, 2),
                ], $language);
                
                $this->telegram->sendMessage($chatId, $summaryMessage);
            }

        } catch (\Exception $e) {
            Log::error('Error in SubscriptionsCommand', [
                'error' => $e->getMessage(),
                'user_id' => $user->id,
            ]);

            $this->telegram->sendMessage(
                $chatId,
                trans('telegram.error_processing', [], $language)
            );
        }
    }
}