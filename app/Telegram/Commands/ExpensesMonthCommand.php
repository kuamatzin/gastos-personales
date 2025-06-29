<?php

namespace App\Telegram\Commands;

use Carbon\Carbon;

class ExpensesMonthCommand extends Command
{
    protected string $name = 'expenses_month';

    public function handle(array $message, string $params = ''): void
    {
        try {
            $this->sendTyping();

            // Parse month from params or use current month
            $targetMonth = $this->parseMonth($params);
            if (! $targetMonth) {
                $errorMsg = $this->user->language === 'es'
                    ? "❌ Formato de mes inválido. Intenta: /gastos\_mes enero o /gastos\_mes 01/2024"
                    : "❌ Invalid month format. Try: /expenses\_month january or /expenses\_month 01/2024";
                $this->reply($errorMsg);

                return;
            }

            $monthEnd = $targetMonth->copy()->endOfMonth();
            $lastMonth = $targetMonth->copy()->subMonth();
            $lastMonthEnd = $lastMonth->copy()->endOfMonth();

            // Get expenses for target month (if current month, use the optimized method)
            if ($targetMonth->isSameMonth(Carbon::now($this->user->getTimezone()))) {
                $expenses = $this->user->expensesThisMonth()
                    ->with('category.parent')
                    ->where('status', 'confirmed')
                    ->orderBy('expense_date', 'desc')
                    ->get();
            } else {
                $expenses = $this->user->expenses()
                    ->with('category.parent')
                    ->whereBetween('expense_date', [$targetMonth->format('Y-m-d'), $monthEnd->format('Y-m-d')])
                    ->where('status', 'confirmed')
                    ->orderBy('expense_date', 'desc')
                    ->get();
            }

            // Get last month's expenses for comparison
            $lastMonthExpenses = $this->user->expenses()
                ->with('category.parent')
                ->whereBetween('expense_date', [$lastMonth->format('Y-m-d'), $lastMonthEnd->format('Y-m-d')])
                ->where('status', 'confirmed')
                ->get();

            if ($expenses->isEmpty()) {
                $this->reply($this->trans('telegram.no_expenses'));

                return;
            }

            // Calculate totals by category
            $categoryTotals = $this->calculateCategoryTotals($expenses);
            $lastMonthCategoryTotals = $this->calculateCategoryTotals($lastMonthExpenses);

            $grandTotal = array_sum($categoryTotals);
            $lastMonthTotal = array_sum($lastMonthCategoryTotals);

            // Build response message
            $monthName = ucfirst($targetMonth->translatedFormat('F Y'));
            $message = $this->trans('telegram.expense_month_header', [
                'month' => $targetMonth->translatedFormat('F'),
                'year' => $targetMonth->format('Y'),
            ]);

            // Category breakdown with comparison
            foreach ($categoryTotals as $category => $total) {
                $emoji = $this->getCategoryEmoji($category);
                $lastMonthAmount = $lastMonthCategoryTotals[$category] ?? 0;
                $change = $this->calculatePercentageChange($total, $lastMonthAmount);
                $changeStr = $change !== null ? " ({$change})" : '';

                // Escape underscores in category names for Markdown
                $escapedCategory = str_replace('_', '\_', $category);
                $message .= "{$emoji} *{$escapedCategory}:* ".$this->formatMoney($total).$changeStr."\n";
            }

            // Grand total and comparison
            $message .= $this->trans('telegram.total', ['amount' => number_format($grandTotal, 2)])."\n";

            if ($lastMonthTotal > 0) {
                $totalChange = $this->calculatePercentageChange($grandTotal, $lastMonthTotal);
                $compareText = $this->user->language === 'es' ? '*vs Mes Pasado:*' : '*vs Last Month:*';
                $message .= "📈 {$compareText} {$totalChange}\n";
            }

            $message .= $this->trans('telegram.stats_expense_count', ['count' => $expenses->count()])."\n\n";

            // Top expenses
            $topExpenses = $expenses->sortByDesc('amount')->take(3);
            if ($topExpenses->isNotEmpty()) {
                $topText = $this->user->language === 'es' ? '*Top 3 gastos:*' : '*Top 3 expenses:*';
                $message .= "{$topText}\n";
                foreach ($topExpenses as $expense) {
                    $date = $expense->expense_date->format('d/m');
                    $amount = $this->formatMoney($expense->amount);
                    // Escape special characters in description
                    $description = \Str::limit($expense->description, 25);
                    $description = str_replace(['_', '*', '`', '[', ']'], ['\_', '\*', '\`', '\[', '\]'], $description);
                    $message .= "• {$date} - {$amount} - {$description}\n";
                }
            }

            // Daily average
            $daysInMonth = $targetMonth->daysInMonth;
            $dailyAverage = $grandTotal / $daysInMonth;
            $message .= "\n".$this->trans('telegram.stats_average_daily', ['amount' => number_format($dailyAverage, 2)]);

            // Quick actions
            $keyboard = [
                [
                    ['text' => $this->trans('telegram.button_by_category'), 'callback_data' => 'cmd_category_spending_'.$targetMonth->format('Y-m')],
                    ['text' => $this->trans('telegram.button_today'), 'callback_data' => 'cmd_expenses_today'],
                ],
                [
                    ['text' => $this->trans('telegram.button_previous_month'), 'callback_data' => 'cmd_expenses_month_'.$lastMonth->format('Y-m')],
                    ['text' => $this->trans('telegram.button_next_month'), 'callback_data' => 'cmd_expenses_month_'.$targetMonth->copy()->addMonth()->format('Y-m')],
                ],
                [
                    ['text' => $this->trans('telegram.button_export'), 'callback_data' => 'cmd_export_month_'.$targetMonth->format('Y-m')],
                ],
            ];

            // Try with Markdown first, fallback to plain text if it fails
            try {
                $this->replyWithKeyboard($message, $keyboard, ['parse_mode' => 'Markdown']);
            } catch (\Exception $mdError) {
                $this->logError('Markdown parsing failed, sending plain text', ['error' => $mdError->getMessage()]);

                // Send without markdown
                $plainMessage = str_replace(['*', '`', '_'], '', $message);
                $this->replyWithKeyboard($plainMessage, $keyboard);
            }

            $this->logExecution('viewed', [
                'month' => $targetMonth->format('Y-m'),
                'expense_count' => $expenses->count(),
                'total' => $grandTotal,
            ]);

        } catch (\Exception $e) {
            $this->logError('Failed to show month expenses', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Send a simple fallback message
            $this->reply($this->trans('telegram.error_processing'));
        }
    }

    /**
     * Calculate category totals from expenses
     */
    private function calculateCategoryTotals($expenses): array
    {
        $totals = [];

        foreach ($expenses as $expense) {
            $category = $expense->category->parent ?? $expense->category;
            $categoryName = $category->getTranslatedName($this->user ? $this->user->language : 'es');

            if (! isset($totals[$categoryName])) {
                $totals[$categoryName] = 0;
            }

            $totals[$categoryName] += $expense->amount;
        }

        arsort($totals);

        return $totals;
    }

    /**
     * Calculate percentage change between two values
     */
    private function calculatePercentageChange(float $current, float $previous): ?string
    {
        if ($previous == 0) {
            return $current > 0 ? '+100%' : null;
        }

        $change = (($current - $previous) / $previous) * 100;

        return $this->formatPercentage($change);
    }
}
