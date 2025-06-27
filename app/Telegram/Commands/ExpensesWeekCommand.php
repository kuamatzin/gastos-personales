<?php

namespace App\Telegram\Commands;

use App\Helpers\ExpenseFormatter;
use Carbon\Carbon;

class ExpensesWeekCommand extends Command
{
    protected string $name = 'expenses_week';

    public function handle(array $message, string $params = ''): void
    {
        try {
            $this->sendTyping();

            // Get current week boundaries (Monday to Sunday)
            $startOfWeek = Carbon::now()->startOfWeek();
            $endOfWeek = Carbon::now()->endOfWeek();

            // Get this week's expenses
            $expenses = $this->user->expenses()
                ->with('category.parent')
                ->whereBetween('expense_date', [$startOfWeek, $endOfWeek])
                ->where('status', 'confirmed')
                ->orderBy('expense_date', 'desc')
                ->get();

            if ($expenses->isEmpty()) {
                $this->reply($this->trans('telegram.no_expenses'));

                return;
            }

            // Calculate daily totals
            $dailyTotals = [];
            $categoryTotals = [];
            $grandTotal = 0;

            foreach ($expenses as $expense) {
                $day = $expense->expense_date->format('Y-m-d');
                $category = $expense->category->parent ?? $expense->category;
                $categoryName = $category->getTranslatedName($this->user->language);

                // Daily totals
                if (! isset($dailyTotals[$day])) {
                    $dailyTotals[$day] = 0;
                }
                $dailyTotals[$day] += $expense->amount;

                // Category totals
                if (! isset($categoryTotals[$categoryName])) {
                    $categoryTotals[$categoryName] = 0;
                }
                $categoryTotals[$categoryName] += $expense->amount;

                $grandTotal += $expense->amount;
            }

            // Build message
            $weekRange = ExpenseFormatter::formatPeriod($startOfWeek, $endOfWeek);
            $message = $this->trans('telegram.expense_week_header', [
                'start_date' => $startOfWeek->format('d/m'),
                'end_date' => $endOfWeek->format('d/m/Y'),
            ]);

            // Daily breakdown
            $message .= '*'.($this->user->language === 'es' ? 'Gasto Diario:' : 'Daily Spending:')."*\n";
            $currentDate = $startOfWeek->copy();

            while ($currentDate <= $endOfWeek) {
                $dateKey = $currentDate->format('Y-m-d');
                $dayName = $currentDate->format('D');
                $amount = $dailyTotals[$dateKey] ?? 0;

                if ($currentDate->isToday()) {
                    $dayName .= ' ('.($this->user->language === 'es' ? 'Hoy' : 'Today').')';
                }

                if ($amount > 0) {
                    $message .= "• {$dayName}: ".ExpenseFormatter::formatAmount($amount)."\n";
                } else {
                    $message .= "• {$dayName}: -\n";
                }

                $currentDate->addDay();
            }

            // Category summary
            $message .= "\n*".($this->user->language === 'es' ? 'Por Categoría:' : 'By Category:')."*\n";
            arsort($categoryTotals);

            foreach ($categoryTotals as $category => $total) {
                $emoji = $this->getCategoryEmoji($category);
                $percentage = ($total / $grandTotal) * 100;
                // Escape special characters in category name
                $escapedCategory = $this->escapeMarkdown($category);
                $message .= "{$emoji} {$escapedCategory}: ".ExpenseFormatter::formatAmount($total);
                $message .= ' ('.number_format($percentage, 1)."%)\n";
            }

            // Summary
            $message .= $this->trans('telegram.total', ['amount' => number_format($grandTotal, 2)])."\n";
            $dailyAverage = $grandTotal / 7;
            $message .= $this->trans('telegram.stats_average_daily', ['amount' => number_format($dailyAverage, 2)])."\n";
            $message .= $this->trans('telegram.stats_expense_count', ['count' => $expenses->count()]);

            // Quick actions
            $keyboard = [
                [
                    ['text' => $this->trans('telegram.button_today'), 'callback_data' => 'cmd_expenses_today'],
                    ['text' => $this->trans('telegram.button_this_month'), 'callback_data' => 'cmd_expenses_month'],
                ],
                [
                    ['text' => $this->trans('telegram.button_last_week'), 'callback_data' => 'cmd_expenses_week_last'],
                    ['text' => $this->trans('telegram.button_statistics'), 'callback_data' => 'cmd_stats'],
                ],
            ];

            $this->replyWithKeyboardMarkdown($message, $keyboard);

            $this->logExecution('viewed', [
                'week_start' => $startOfWeek->toDateString(),
                'expense_count' => $expenses->count(),
                'total' => $grandTotal,
            ]);

        } catch (\Exception $e) {
            $this->logError('Failed to show week expenses', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Send a simple fallback message
            $this->reply($this->trans('telegram.error_processing'));
        }
    }
}
