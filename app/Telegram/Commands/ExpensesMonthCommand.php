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
            if (!$targetMonth) {
                $this->reply("❌ Invalid month format. Try: /expenses\_month january or /expenses\_month 01/2024");
                return;
            }
        
        $monthEnd = $targetMonth->copy()->endOfMonth();
        $lastMonth = $targetMonth->copy()->subMonth();
        $lastMonthEnd = $lastMonth->copy()->endOfMonth();
        
        // Get expenses for target month
        $expenses = $this->user->expenses()
            ->with('category.parent')
            ->whereBetween('expense_date', [$targetMonth, $monthEnd])
            ->where('status', 'confirmed')
            ->orderBy('expense_date', 'desc')
            ->get();
        
        // Get last month's expenses for comparison
        $lastMonthExpenses = $this->user->expenses()
            ->with('category.parent')
            ->whereBetween('expense_date', [$lastMonth, $lastMonthEnd])
            ->where('status', 'confirmed')
            ->get();
        
        if ($expenses->isEmpty()) {
            $monthName = ucfirst($targetMonth->translatedFormat('F Y'));
            $this->sendNoExpensesMessage($monthName);
            return;
        }
        
        // Calculate totals by category
        $categoryTotals = $this->calculateCategoryTotals($expenses);
        $lastMonthCategoryTotals = $this->calculateCategoryTotals($lastMonthExpenses);
        
        $grandTotal = array_sum($categoryTotals);
        $lastMonthTotal = array_sum($lastMonthCategoryTotals);
        
        // Build response message
        $monthName = ucfirst($targetMonth->translatedFormat('F Y'));
        $message = "📅 *{$monthName} Expenses*\n\n";
        
        // Category breakdown with comparison
        foreach ($categoryTotals as $category => $total) {
            $emoji = $this->getCategoryEmoji($category);
            $lastMonthAmount = $lastMonthCategoryTotals[$category] ?? 0;
            $change = $this->calculatePercentageChange($total, $lastMonthAmount);
            $changeStr = $change !== null ? " ({$change})" : "";
            
            // Escape underscores in category names for Markdown
            $escapedCategory = str_replace('_', '\_', $category);
            $message .= "{$emoji} *{$escapedCategory}:* " . $this->formatMoney($total) . $changeStr . "\n";
        }
        
        // Grand total and comparison
        $message .= "\n📊 *Total:* " . $this->formatMoney($grandTotal) . "\n";
        
        if ($lastMonthTotal > 0) {
            $totalChange = $this->calculatePercentageChange($grandTotal, $lastMonthTotal);
            $message .= "📈 *vs Last Month:* {$totalChange}\n";
        }
        
        $message .= "💡 *{$expenses->count()} expenses recorded*\n\n";
        
        // Top expenses
        $topExpenses = $expenses->sortByDesc('amount')->take(3);
        if ($topExpenses->isNotEmpty()) {
            $message .= "*Top 3 expenses:*\n";
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
        $message .= "\n💰 *Daily Average:* " . $this->formatMoney($dailyAverage);
        
        // Quick actions
        $keyboard = [
            [
                ['text' => '🏷️ By Category', 'callback_data' => 'cmd_category_spending_' . $targetMonth->format('Y-m')],
                ['text' => '📊 Today', 'callback_data' => 'cmd_expenses_today']
            ],
            [
                ['text' => '⬅️ Previous Month', 'callback_data' => 'cmd_expenses_month_' . $lastMonth->format('Y-m')],
                ['text' => '➡️ Next Month', 'callback_data' => 'cmd_expenses_month_' . $targetMonth->copy()->addMonth()->format('Y-m')]
            ],
            [
                ['text' => '📤 Export', 'callback_data' => 'cmd_export_month_' . $targetMonth->format('Y-m')]
            ]
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
            'total' => $grandTotal
        ]);
        
        } catch (\Exception $e) {
            $this->logError('Failed to show month expenses', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Send a simple fallback message
            $this->reply("❌ Sorry, I couldn't retrieve the monthly expenses. Please try again later.");
        }
    }
    
    /**
     * Calculate category totals from expenses
     */
    private function calculateCategoryTotals($expenses): array
    {
        $totals = [];
        
        foreach ($expenses as $expense) {
            $categoryName = $expense->category->parent->name ?? $expense->category->name;
            
            if (!isset($totals[$categoryName])) {
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