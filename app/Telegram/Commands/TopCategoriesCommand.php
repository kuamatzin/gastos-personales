<?php

namespace App\Telegram\Commands;

use App\Helpers\ExpenseFormatter;
use App\Services\ExpenseAnalyticsService;
use Carbon\Carbon;

class TopCategoriesCommand extends Command
{
    protected string $name = 'top_categories';

    private ExpenseAnalyticsService $analytics;

    public function __construct($telegram, $user)
    {
        parent::__construct($telegram, $user);
        $this->analytics = new ExpenseAnalyticsService;
    }

    public function handle(array $message, string $params = ''): void
    {
        try {
            $this->sendTyping();

            // Parse period from params (default to current month)
            $period = $this->parsePeriod($params);

            // Get top categories
            $topCategories = $this->analytics->getTopCategories(
                $this->user,
                $period['start'],
                $period['end'],
                10
            );

            if ($topCategories->isEmpty()) {
                $this->sendNoExpensesMessage($period['name']);

                return;
            }

            // Calculate total
            $total = $topCategories->sum('total');

            // Build message
            $message = "🏆 *Top Categories*\n";
            $message .= "_{$period['name']}_\n\n";

            $position = 1;
            foreach ($topCategories as $category) {
                $emoji = $this->getCategoryEmoji($category->parent_category);
                $percentage = ($category->total / $total) * 100;
                $medal = $this->getPositionMedal($position);

                $escapedCategory = $this->escapeMarkdown($category->parent_category);
                $message .= "{$medal} {$emoji} *{$escapedCategory}*\n";
                $message .= '   '.ExpenseFormatter::formatAmount($category->total);
                $message .= ' • '.number_format($percentage, 1).'%';
                $message .= " • {$category->count} expenses\n";

                // Show subcategory if different from parent
                if ($category->category_name !== $category->parent_category) {
                    $escapedSubcategory = $this->escapeMarkdown($category->category_name);
                    $message .= "   _{$escapedSubcategory}_\n";
                }

                $message .= "\n";

                $position++;
            }

            // Summary
            $message .= '📊 *Total:* '.ExpenseFormatter::formatAmount($total)."\n";
            $message .= '🏷️ *Categories:* '.$topCategories->count();

            // Quick actions
            $keyboard = [
                [
                    ['text' => '📅 Change Period', 'callback_data' => 'top_categories_period'],
                    ['text' => '📊 Details', 'callback_data' => 'cmd_category_spending'],
                ],
                [
                    ['text' => '📈 Trends', 'callback_data' => 'category_trends'],
                    ['text' => '📤 Export', 'callback_data' => 'export_categories'],
                ],
            ];

            $this->replyWithKeyboardMarkdown($message, $keyboard);

            $this->logExecution('viewed', [
                'period' => $period['name'],
                'category_count' => $topCategories->count(),
            ]);

        } catch (\Exception $e) {
            $this->logError('Failed to show top categories', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Send a simple fallback message
            $this->reply("❌ Sorry, I couldn't retrieve top categories. Please try again later.");
        }
    }

    /**
     * Parse period from parameters
     */
    private function parsePeriod(string $params): array
    {
        $params = strtolower(trim($params));

        if (empty($params) || $params === 'month' || $params === 'mes') {
            // Current month
            $start = Carbon::now()->startOfMonth();
            $end = Carbon::now()->endOfMonth();
            $name = ucfirst($start->translatedFormat('F Y'));
        } elseif ($params === 'week' || $params === 'semana') {
            // Current week
            $start = Carbon::now()->startOfWeek();
            $end = Carbon::now()->endOfWeek();
            $name = 'This Week';
        } elseif ($params === 'year' || $params === 'año') {
            // Current year
            $start = Carbon::now()->startOfYear();
            $end = Carbon::now()->endOfYear();
            $name = 'Year '.$start->year;
        } elseif ($params === 'all' || $params === 'todo') {
            // All time
            $start = $this->user->expenses()->min('expense_date') ?? Carbon::now();
            $end = Carbon::now();
            $name = 'All Time';
        } else {
            // Try to parse as month
            $month = $this->parseMonth($params);
            if ($month) {
                $start = $month;
                $end = $month->copy()->endOfMonth();
                $name = ucfirst($month->translatedFormat('F Y'));
            } else {
                // Default to current month
                $start = Carbon::now()->startOfMonth();
                $end = Carbon::now()->endOfMonth();
                $name = ucfirst($start->translatedFormat('F Y'));
            }
        }

        return [
            'start' => $start,
            'end' => $end,
            'name' => $name,
        ];
    }

    /**
     * Get medal emoji for position
     */
    private function getPositionMedal(int $position): string
    {
        switch ($position) {
            case 1:
                return '🥇';
            case 2:
                return '🥈';
            case 3:
                return '🥉';
            default:
                return "{$position}.";
        }
    }
}
