<?php

namespace App\Telegram\Commands;

use App\Models\Category;
use Carbon\Carbon;

class CategorySpendingCommand extends Command
{
    protected string $name = 'category_spending';

    public function handle(array $message, string $params = ''): void
    {
        try {
            $this->sendTyping();

            // Check if specific category requested
            if (! empty($params)) {
                $this->showCategoryDetails($params);

                return;
            }

            // Show all categories for current month
            $this->showAllCategories();

        } catch (\Exception $e) {
            $this->logError('Failed to show category spending', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Send a simple fallback message
            $this->reply("❌ Sorry, I couldn't retrieve category spending. Please try again later.");
        }
    }

    /**
     * Show spending for all categories
     */
    private function showAllCategories(): void
    {
        $currentMonth = Carbon::now()->startOfMonth();
        $monthEnd = $currentMonth->copy()->endOfMonth();

        // Get expenses grouped by category
        $expenses = $this->user->expenses()
            ->with('category.parent', 'category.children')
            ->whereBetween('expense_date', [$currentMonth, $monthEnd])
            ->where('status', 'confirmed')
            ->get();

        if ($expenses->isEmpty()) {
            $this->sendNoExpensesMessage('this month');

            return;
        }

        // Group by parent category and calculate totals
        $categoryData = [];
        $grandTotal = 0;

        foreach ($expenses as $expense) {
            $parentCategory = $expense->category->parent ?? $expense->category;
            $subcategory = $expense->category->parent ? $expense->category : null;

            $parentName = $parentCategory->name;

            if (! isset($categoryData[$parentName])) {
                $categoryData[$parentName] = [
                    'total' => 0,
                    'subcategories' => [],
                    'count' => 0,
                ];
            }

            $categoryData[$parentName]['total'] += $expense->amount;
            $categoryData[$parentName]['count']++;
            $grandTotal += $expense->amount;

            if ($subcategory) {
                $subName = $subcategory->name;
                if (! isset($categoryData[$parentName]['subcategories'][$subName])) {
                    $categoryData[$parentName]['subcategories'][$subName] = [
                        'total' => 0,
                        'count' => 0,
                    ];
                }
                $categoryData[$parentName]['subcategories'][$subName]['total'] += $expense->amount;
                $categoryData[$parentName]['subcategories'][$subName]['count']++;
            }
        }

        // Sort by total amount
        uasort($categoryData, function ($a, $b) {
            return $b['total'] <=> $a['total'];
        });

        // Build message
        $monthName = ucfirst($currentMonth->translatedFormat('F Y'));
        $message = "🏷️ *Category Spending - {$monthName}*\n\n";

        foreach ($categoryData as $categoryName => $data) {
            $emoji = $this->getCategoryEmoji($categoryName);
            $percentage = ($data['total'] / $grandTotal) * 100;

            $escapedCategory = $this->escapeMarkdown($categoryName);
            $message .= "{$emoji} *{$escapedCategory}:* ".$this->formatMoney($data['total']);
            $message .= ' ('.number_format($percentage, 1)."%)\n";

            // Show top subcategories if any
            if (! empty($data['subcategories'])) {
                arsort($data['subcategories']);
                $topSubs = array_slice($data['subcategories'], 0, 3, true);

                foreach ($topSubs as $subName => $subData) {
                    $escapedSubName = $this->escapeMarkdown($subName);
                    $message .= "  └ {$escapedSubName}: ".$this->formatMoney($subData['total'])."\n";
                }

                if (count($data['subcategories']) > 3) {
                    $message .= '  └ _... and '.(count($data['subcategories']) - 3)." more_\n";
                }
            }

            $message .= "\n";
        }

        $message .= '📊 *Total:* '.$this->formatMoney($grandTotal)."\n";
        $message .= "📈 *{$expenses->count()} expenses in ".count($categoryData)." categories*\n\n";
        $message .= '💡 _Tap a category below for details_';

        // Create category buttons
        $keyboard = [];
        $row = [];
        $count = 0;

        foreach (array_keys($categoryData) as $categoryName) {
            $emoji = $this->getCategoryEmoji($categoryName);
            $row[] = [
                'text' => $emoji.' '.$categoryName,
                'callback_data' => 'category_detail_'.strtolower($categoryName),
            ];

            $count++;
            if ($count % 2 == 0) {
                $keyboard[] = $row;
                $row = [];
            }
        }

        if (! empty($row)) {
            $keyboard[] = $row;
        }

        // Add navigation row
        $keyboard[] = [
            ['text' => '📅 This Month', 'callback_data' => 'cmd_expenses_month'],
            ['text' => '📊 Top Categories', 'callback_data' => 'cmd_top_categories'],
        ];

        $this->replyWithKeyboardMarkdown($message, $keyboard);

        $this->logExecution('all_categories', [
            'month' => $currentMonth->format('Y-m'),
            'category_count' => count($categoryData),
            'total' => $grandTotal,
        ]);
    }

    /**
     * Show details for specific category
     */
    private function showCategoryDetails(string $categoryName): void
    {
        $currentMonth = Carbon::now()->startOfMonth();
        $monthEnd = $currentMonth->copy()->endOfMonth();

        // Find category (case insensitive)
        $category = Category::whereRaw('LOWER(name) = ?', [strtolower(trim($categoryName))])
            ->first();

        if (! $category) {
            $this->reply("❌ Category '{$categoryName}' not found. Use /category_spending to see all categories.");

            return;
        }

        // Get expenses for this category and its subcategories
        $categoryIds = [$category->id];
        if ($category->children->isNotEmpty()) {
            $categoryIds = array_merge($categoryIds, $category->children->pluck('id')->toArray());
        }

        $expenses = $this->user->expenses()
            ->with('category')
            ->whereIn('category_id', $categoryIds)
            ->whereBetween('expense_date', [$currentMonth, $monthEnd])
            ->where('status', 'confirmed')
            ->orderBy('expense_date', 'desc')
            ->get();

        if ($expenses->isEmpty()) {
            $this->reply("📊 No expenses found for *{$category->name}* this month.", ['parse_mode' => 'Markdown']);

            return;
        }

        // Calculate totals
        $total = $expenses->sum('amount');
        $dailyAverage = $total / $currentMonth->daysInMonth;

        // Build message
        $emoji = $this->getCategoryEmoji($category->name);
        $monthName = ucfirst($currentMonth->translatedFormat('F'));
        $message = "{$emoji} *{$category->name} - {$monthName}*\n\n";

        $message .= '💰 *Total:* '.$this->formatMoney($total)."\n";
        $message .= '📊 *Daily Average:* '.$this->formatMoney($dailyAverage)."\n";
        $message .= "📈 *Transactions:* {$expenses->count()}\n\n";

        // Recent expenses
        $message .= "*Recent expenses:*\n";
        foreach ($expenses->take(10) as $expense) {
            $date = $expense->expense_date->format('d/m');
            $amount = $this->formatMoney($expense->amount);
            $description = $this->escapeMarkdown(\Str::limit($expense->description, 30));
            $message .= "• {$date} - {$amount} - {$description}\n";
        }

        if ($expenses->count() > 10) {
            $message .= '• _... and '.($expenses->count() - 10)." more_\n";
        }

        // Quick actions
        $keyboard = [
            [
                ['text' => '⬅️ All Categories', 'callback_data' => 'cmd_category_spending'],
                ['text' => '📤 Export', 'callback_data' => 'export_category_'.$category->id],
            ],
        ];

        $this->replyWithKeyboardMarkdown($message, $keyboard);

        $this->logExecution('category_detail', [
            'category' => $category->name,
            'expense_count' => $expenses->count(),
            'total' => $total,
        ]);
    }
}
