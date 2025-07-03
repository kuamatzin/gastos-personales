<?php

namespace App\Telegram\Commands;

use App\Telegram\Commands\BaseCommand;
use App\Models\Subscription;
use Illuminate\Support\Facades\Log;

class SubscriptionsCommand extends BaseCommand
{
    protected string $name = 'subscriptions';
    protected string $nameEs = 'suscripciones';
    protected string $description = 'View active subscriptions';
    protected string $descriptionEs = 'Ver suscripciones activas';

    public function handle(array $message, string $params = ''): void
    {
        $chatId = $message['chat']['id'];
        $user = $this->getUser($message);
        $language = $user->language ?? 'es';

        try {
            // Get active subscriptions
            $subscriptions = $user->subscriptions()
                ->where('status', 'active')
                ->orderBy('next_charge_date')
                ->get();

            if ($subscriptions->isEmpty()) {
                $this->telegram->sendMessage(
                    $chatId,
                    trans('telegram.no_subscriptions', [], $language)
                );
                return;
            }

            // Build message
            $message = trans('telegram.subscriptions_title', [], $language)."\n\n";
            
            $totalMonthly = 0;
            
            foreach ($subscriptions as $subscription) {
                $periodicityText = $subscription->getPeriodicityText($language);
                
                $message .= trans('telegram.subscription_info', [
                    'name' => $subscription->name,
                    'amount' => number_format($subscription->amount, 2),
                    'currency' => $subscription->currency,
                    'periodicity' => $periodicityText,
                    'next_charge' => $subscription->next_charge_date->format('d/m/Y'),
                ], $language)."\n\n";
                
                // Calculate monthly equivalent
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
            
            if ($totalMonthly > 0) {
                $message .= "\n💰 ".trans('telegram.total_monthly_subscriptions', [
                    'amount' => number_format($totalMonthly, 2),
                ], $language);
            }

            $this->telegram->sendMessage($chatId, $message, [
                'parse_mode' => 'Markdown',
            ]);

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