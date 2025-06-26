<?php

namespace App\Telegram\Commands;

use Carbon\Carbon;
use App\Helpers\ExpenseFormatter;

class ExpensesWeekCommand extends Command
{
    protected string $name = 'expenses_week';
    
    public function handle(array $message, string $params = ''): void
    {
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
            $this->sendNoExpensesMessage('this week');
            return;
        }
        
        // Calculate daily totals
        $dailyTotals = [];
        $categoryTotals = [];
        $grandTotal = 0;
        
        foreach ($expenses as $expense) {
            $day = $expense->expense_date->format('Y-m-d');
            $categoryName = $expense->category->parent->name ?? $expense->category->name;
            
            // Daily totals
            if (!isset($dailyTotals[$day])) {
                $dailyTotals[$day] = 0;
            }
            $dailyTotals[$day] += $expense->amount;
            
            // Category totals
            if (!isset($categoryTotals[$categoryName])) {
                $categoryTotals[$categoryName] = 0;
            }
            $categoryTotals[$categoryName] += $expense->amount;
            
            $grandTotal += $expense->amount;
        }
        
        // Build message
        $weekRange = ExpenseFormatter::formatPeriod($startOfWeek, $endOfWeek);
        $message = "📊 *This Week's Expenses*\n";
        $message .= "_{$weekRange}_\n\n";
        
        // Daily breakdown
        $message .= "*Daily Spending:*\n";
        $currentDate = $startOfWeek->copy();
        
        while ($currentDate <= $endOfWeek) {
            $dateKey = $currentDate->format('Y-m-d');
            $dayName = $currentDate->format('D');
            $amount = $dailyTotals[$dateKey] ?? 0;
            
            if ($currentDate->isToday()) {
                $dayName .= ' (Today)';
            }
            
            if ($amount > 0) {
                $message .= "• {$dayName}: " . ExpenseFormatter::formatAmount($amount) . "\n";
            } else {
                $message .= "• {$dayName}: -\n";
            }
            
            $currentDate->addDay();
        }
        
        // Category summary
        $message .= "\n*By Category:*\n";
        arsort($categoryTotals);
        
        foreach ($categoryTotals as $category => $total) {
            $emoji = $this->getCategoryEmoji($category);
            $percentage = ($total / $grandTotal) * 100;
            $message .= "{$emoji} {$category}: " . ExpenseFormatter::formatAmount($total);
            $message .= " (" . number_format($percentage, 1) . "%)\n";
        }
        
        // Summary
        $message .= "\n📊 *Total:* " . ExpenseFormatter::formatAmount($grandTotal) . "\n";
        $dailyAverage = $grandTotal / 7;
        $message .= "📈 *Daily Average:* " . ExpenseFormatter::formatAmount($dailyAverage) . "\n";
        $message .= "💡 *{$expenses->count()} expenses recorded*";
        
        // Quick actions
        $keyboard = [
            [
                ['text' => '📊 Today', 'callback_data' => 'cmd_expenses_today'],
                ['text' => '📅 This Month', 'callback_data' => 'cmd_expenses_month']
            ],
            [
                ['text' => '⬅️ Last Week', 'callback_data' => 'cmd_expenses_week_last'],
                ['text' => '📈 Statistics', 'callback_data' => 'cmd_stats']
            ]
        ];
        
        $this->replyWithKeyboard($message, $keyboard, ['parse_mode' => 'Markdown']);
        
        $this->logExecution('viewed', [
            'week_start' => $startOfWeek->toDateString(),
            'expense_count' => $expenses->count(),
            'total' => $grandTotal
        ]);
    }
}