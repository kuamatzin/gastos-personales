<?php

namespace App\Telegram\Commands;

use Carbon\Carbon;

class ExpensesTodayCommand extends Command
{
    protected string $name = 'expenses_today';
    
    public function handle(array $message, string $params = ''): void
    {
        $this->sendTyping();
        
        $today = Carbon::today();
        
        // Get today's expenses
        $expenses = $this->user->expenses()
            ->with('category.parent')
            ->whereDate('expense_date', $today)
            ->where('status', 'confirmed')
            ->orderBy('created_at', 'desc')
            ->get();
        
        if ($expenses->isEmpty()) {
            $this->sendNoExpensesMessage('today');
            return;
        }
        
        // Calculate totals by category
        $categoryTotals = [];
        $grandTotal = 0;
        
        foreach ($expenses as $expense) {
            $categoryName = $expense->category->parent->name ?? $expense->category->name;
            
            if (!isset($categoryTotals[$categoryName])) {
                $categoryTotals[$categoryName] = 0;
            }
            
            $categoryTotals[$categoryName] += $expense->amount;
            $grandTotal += $expense->amount;
        }
        
        // Sort categories by total amount
        arsort($categoryTotals);
        
        // Build response message
        $message = "💰 *Today's Expenses* ";
        $message .= "(" . $today->format('d/m/Y') . ")\n\n";
        
        // Category breakdown
        foreach ($categoryTotals as $category => $total) {
            $emoji = $this->getCategoryEmoji($category);
            $message .= "{$emoji} *{$category}:* " . $this->formatMoney($total) . "\n";
        }
        
        $message .= "\n📊 *Total:* " . $this->formatMoney($grandTotal) . "\n";
        $message .= "📈 *{$expenses->count()} expenses recorded*\n\n";
        
        // Recent expenses list
        $message .= "*Recent expenses:*\n";
        $recentExpenses = $expenses->take(5);
        
        foreach ($recentExpenses as $expense) {
            $time = $expense->created_at->format('H:i');
            $amount = $this->formatMoney($expense->amount);
            $description = \Str::limit($expense->description, 30);
            $message .= "• {$time} - {$amount} - {$description}\n";
        }
        
        if ($expenses->count() > 5) {
            $message .= "• _... and " . ($expenses->count() - 5) . " more_\n";
        }
        
        // Quick actions
        $keyboard = [
            [
                ['text' => '📅 This Month', 'callback_data' => 'cmd_expenses_month'],
                ['text' => '📊 This Week', 'callback_data' => 'cmd_expenses_week']
            ],
            [
                ['text' => '📈 Statistics', 'callback_data' => 'cmd_stats'],
                ['text' => '📤 Export', 'callback_data' => 'cmd_export_today']
            ]
        ];
        
        $this->replyWithKeyboard($message, $keyboard, ['parse_mode' => 'Markdown']);
        
        $this->logExecution('viewed', [
            'date' => $today->toDateString(),
            'expense_count' => $expenses->count(),
            'total' => $grandTotal
        ]);
    }
}