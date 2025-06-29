<?php

namespace App\Telegram\Commands;

use Carbon\Carbon;

class ExpensesTodayCommand extends Command
{
    protected string $name = 'expenses_today';

    public function handle(array $message, string $params = ''): void
    {
        try {
            $this->sendTyping();

            // Get today in user's timezone
            $today = Carbon::now($this->user->getTimezone())->startOfDay();

            // Get today's expenses using the timezone-aware method
            $expenses = $this->user->expensesToday()
                ->with('category.parent')
                ->where('status', 'confirmed')
                ->orderBy('created_at', 'desc')
                ->get();

            if ($expenses->isEmpty()) {
                $this->reply($this->trans('telegram.no_expenses'));

                return;
            }

            // Calculate totals by category
            $categoryTotals = [];
            $grandTotal = 0;

            foreach ($expenses as $expense) {
                $category = $expense->category->parent ?? $expense->category;
                $categoryName = $category->getTranslatedName($this->user->language);

                if (! isset($categoryTotals[$categoryName])) {
                    $categoryTotals[$categoryName] = 0;
                }

                $categoryTotals[$categoryName] += $expense->amount;
                $grandTotal += $expense->amount;
            }

            // Sort categories by total amount
            arsort($categoryTotals);

            // Build response message
            $message = $this->trans('telegram.expense_today_header', [
                'date' => $today->format('d/m/Y'),
            ]);

            // Category breakdown
            foreach ($categoryTotals as $category => $total) {
                $emoji = $this->getCategoryEmoji($category);
                // Escape underscores in category names for Markdown
                $escapedCategory = str_replace('_', '\\_', $category);
                $message .= "{$emoji} *{$escapedCategory}:* ".$this->formatMoney($total)."\n";
            }

            $message .= $this->trans('telegram.total', ['amount' => number_format($grandTotal, 2)])."\n";
            $message .= $this->trans('telegram.stats_expense_count', ['count' => $expenses->count()])."\n";

            // Recent expenses list
            $message .= "\n*".($this->user->language === 'es' ? 'Gastos recientes:' : 'Recent expenses:')."*\n";
            $recentExpenses = $expenses->take(5);

            foreach ($recentExpenses as $expense) {
                $time = $expense->created_at->format('H:i');
                $amount = $this->formatMoney($expense->amount);
                // Escape special characters in description
                $description = \Str::limit($expense->description, 30);
                $description = str_replace(['_', '*', '`', '[', ']'], ['\_', '\*', '\`', '\[', '\]'], $description);
                $message .= "• {$time} - {$amount} - {$description}\n";
            }

            if ($expenses->count() > 5) {
                $remaining = $expenses->count() - 5;
                $message .= '• _... '.($this->user->language === 'es' ? "y {$remaining} más" : "and {$remaining} more")."_\n";
            }

            // Quick actions
            $keyboard = [
                [
                    ['text' => $this->trans('telegram.button_this_month'), 'callback_data' => 'cmd_expenses_month'],
                    ['text' => $this->trans('telegram.button_this_week'), 'callback_data' => 'cmd_expenses_week'],
                ],
                [
                    ['text' => $this->trans('telegram.button_statistics'), 'callback_data' => 'cmd_stats'],
                    ['text' => $this->trans('telegram.button_export'), 'callback_data' => 'cmd_export_today'],
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
                'date' => $today->toDateString(),
                'expense_count' => $expenses->count(),
                'total' => $grandTotal,
            ]);

        } catch (\Exception $e) {
            $this->logError('Failed to show today expenses', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Send a simple fallback message
            $this->reply($this->trans('telegram.error_processing'));
        }
    }
}
