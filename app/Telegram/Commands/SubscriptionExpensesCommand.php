<?php

namespace App\Telegram\Commands;

use App\Models\SubscriptionExpense;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class SubscriptionExpensesCommand extends Command
{
    protected string $name = 'subscription_expenses';

    public function handle(array $message, string $params = ''): void
    {
        $chatId = $message['chat']['id'];
        $user = $this->user;
        $language = $user->language ?? 'es';

        try {
            $timezone = $user->getTimezone();
            $now = Carbon::now($timezone);
            $startOfMonth = $now->copy()->startOfMonth();
            $endOfMonth = $now->copy()->endOfMonth();

            // Get subscription expenses for this month
            $subscriptionExpenses = SubscriptionExpense::whereHas('subscription', function ($query) use ($user) {
                    $query->where('user_id', $user->id);
                })
                ->whereHas('expense')
                ->whereBetween('charge_date', [$startOfMonth, $endOfMonth])
                ->with(['subscription', 'expense'])
                ->orderBy('charge_date', 'desc')
                ->get();

            if ($subscriptionExpenses->isEmpty()) {
                $this->telegram->sendMessage(
                    $chatId,
                    trans('telegram.no_subscription_expenses', [], $language)
                );
                return;
            }

            // Build message
            $monthName = $now->locale($language)->monthName;
            $message = trans('telegram.subscription_expenses_title', [
                'month' => ucfirst($monthName)
            ], $language)."\n\n";
            
            $total = 0;
            
            foreach ($subscriptionExpenses as $subExpense) {
                $subscription = $subExpense->subscription;
                $expense = $subExpense->expense;
                
                $message .= trans('telegram.subscription_expense_info', [
                    'date' => $subExpense->charge_date->format('d/m'),
                    'name' => $subscription->name,
                    'amount' => number_format($expense->amount, 2),
                    'currency' => $expense->currency,
                ], $language)."\n";
                
                $total += $expense->amount;
            }
            
            $message .= "\n💰 ".trans('telegram.total', [
                'amount' => number_format($total, 2),
            ], $language);

            // Calculate percentage of total monthly expenses
            $totalMonthlyExpenses = $user->expenses()
                ->whereBetween('expense_date', [$startOfMonth, $endOfMonth])
                ->where('status', 'confirmed')
                ->sum('amount');
            
            if ($totalMonthlyExpenses > 0) {
                $percentage = round(($total / $totalMonthlyExpenses) * 100, 1);
                $message .= "\n📊 ".trans('telegram.percentage_of_total', [
                    'percentage' => $percentage,
                ], $language);
            }

            $this->telegram->sendMessage($chatId, $message, [
                'parse_mode' => 'Markdown',
            ]);

        } catch (\Exception $e) {
            Log::error('Error in SubscriptionExpensesCommand', [
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