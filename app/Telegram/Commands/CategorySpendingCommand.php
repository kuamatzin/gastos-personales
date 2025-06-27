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
            $this->reply($this->trans('telegram.error_processing'));
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
            $this->reply($this->trans('telegram.no_expenses'));

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
        $periodText = $this->user->language === 'es' ? $monthName : $monthName;
        $message = $this->trans('telegram.category_spending_header', [
            'period' => $periodText,
        ]);

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
                    $remaining = count($data['subcategories']) - 3;
                    $moreText = $this->user->language === 'es' ? "y {$remaining} más" : "and {$remaining} more";
                    $message .= "  └ _... {$moreText}_\n";
                }
            }

            $message .= "\n";
        }

        $message .= $this->trans('telegram.total', ['amount' => number_format($grandTotal, 2)])."\n";
        $expenseText = $this->user->language === 'es'
            ? "*{$expenses->count()} gastos en ".count($categoryData).' categorías*'
            : "*{$expenses->count()} expenses in ".count($categoryData).' categories*';
        $message .= "📈 {$expenseText}\n\n";
        $tipText = $this->user->language === 'es' ? 'Toca una categoría para ver detalles' : 'Tap a category below for details';
        $message .= "💡 _{$tipText}_";

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
            ['text' => $this->trans('telegram.button_this_month'), 'callback_data' => 'cmd_expenses_month'],
            ['text' => $this->trans('telegram.button_top_categories'), 'callback_data' => 'cmd_top_categories'],
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
            $errorMsg = $this->user->language === 'es'
                ? "❌ Categoría '{$categoryName}' no encontrada. Usa /gastos_categoria para ver todas las categorías."
                : "❌ Category '{$categoryName}' not found. Use /category_spending to see all categories.";
            $this->reply($errorMsg);

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
            $noExpensesMsg = $this->user->language === 'es'
                ? "📊 No se encontraron gastos para *{$category->name}* este mes."
                : "📊 No expenses found for *{$category->name}* this month.";
            $this->reply($noExpensesMsg, ['parse_mode' => 'Markdown']);

            return;
        }

        // Calculate totals
        $total = $expenses->sum('amount');
        $dailyAverage = $total / $currentMonth->daysInMonth;

        // Build message
        $emoji = $this->getCategoryEmoji($category->name);
        $monthName = ucfirst($currentMonth->translatedFormat('F'));
        $message = "{$emoji} *{$category->name} - {$monthName}*\n\n";

        $message .= $this->trans('telegram.total', ['amount' => number_format($total, 2)])."\n";
        $message .= $this->trans('telegram.stats_average_daily', ['amount' => number_format($dailyAverage, 2)])."\n";
        $transText = $this->user->language === 'es' ? 'Transacciones' : 'Transactions';
        $message .= "📈 *{$transText}:* {$expenses->count()}\n\n";

        // Recent expenses
        $recentText = $this->user->language === 'es' ? 'Gastos recientes' : 'Recent expenses';
        $message .= "*{$recentText}:*\n";
        foreach ($expenses->take(10) as $expense) {
            $date = $expense->expense_date->format('d/m');
            $amount = $this->formatMoney($expense->amount);
            $description = $this->escapeMarkdown(\Str::limit($expense->description, 30));
            $message .= "• {$date} - {$amount} - {$description}\n";
        }

        if ($expenses->count() > 10) {
            $remaining = $expenses->count() - 10;
            $moreText = $this->user->language === 'es' ? "y {$remaining} más" : "and {$remaining} more";
            $message .= "• _... {$moreText}_\n";
        }

        // Quick actions
        $keyboard = [
            [
                ['text' => $this->trans('telegram.button_all_categories'), 'callback_data' => 'cmd_category_spending'],
                ['text' => $this->trans('telegram.button_export'), 'callback_data' => 'export_category_'.$category->id],
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
