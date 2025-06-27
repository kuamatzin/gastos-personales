<?php

namespace App\Telegram\Commands;

use App\Helpers\ExpenseFormatter;
use App\Services\ExpenseAnalyticsService;

class StatsCommand extends Command
{
    protected string $name = 'stats';

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

            if (! $this->userHasExpenses()) {
                $this->sendNoExpensesMessage();

                return;
            }

            // Build statistics message
            $message = "📈 *Expense Statistics*\n\n";

            // Spending trends
            $message .= $this->getSpendingTrends();

            // Insights
            $message .= $this->getInsights();

            // Records
            $message .= $this->getRecords();

            // Prediction
            $message .= $this->getPrediction();

            // Quick actions
            $keyboard = [
                [
                    ['text' => '📅 Monthly Trends', 'callback_data' => 'stats_monthly_trends'],
                    ['text' => '⏰ By Hour', 'callback_data' => 'stats_by_hour'],
                ],
                [
                    ['text' => '📊 By Day', 'callback_data' => 'stats_by_day'],
                    ['text' => '🏷️ Categories', 'callback_data' => 'cmd_top_categories'],
                ],
                [
                    ['text' => '📤 Export Report', 'callback_data' => 'export_report'],
                ],
            ];

            $this->replyWithKeyboardMarkdown($message, $keyboard);

            $this->logExecution('viewed');

        } catch (\Exception $e) {
            $this->logError('Failed to show statistics', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Send a simple fallback message
            $this->reply("❌ Sorry, I couldn't generate statistics. Please try again later.");
        }
    }

    /**
     * Get spending trends section
     */
    private function getSpendingTrends(): string
    {
        $message = "🔥 *Spending Trends:*\n";

        // Day of week analysis
        $daySpending = $this->analytics->getSpendingByDayOfWeek($this->user);
        $maxDay = '';
        $maxAmount = 0;
        $minDay = '';
        $minAmount = PHP_INT_MAX;

        foreach ($daySpending as $day => $data) {
            if ($data['average'] > $maxAmount) {
                $maxAmount = $data['average'];
                $maxDay = $day;
            }
            if ($data['average'] < $minAmount && $data['average'] > 0) {
                $minAmount = $data['average'];
                $minDay = $day;
            }
        }

        if ($maxDay) {
            $message .= "• Highest day: {$maxDay} (".ExpenseFormatter::formatAmount($maxAmount)." avg)\n";
        }
        if ($minDay) {
            $message .= "• Lowest day: {$minDay} (".ExpenseFormatter::formatAmount($minAmount)." avg)\n";
        }

        // Peak hour analysis
        $hourSpending = $this->analytics->getSpendingByHour($this->user);
        $peakHour = 0;
        $peakCount = 0;

        foreach ($hourSpending as $hour => $data) {
            if ($data['count'] > $peakCount) {
                $peakCount = $data['count'];
                $peakHour = $hour;
            }
        }

        $peakTime = $this->formatHourRange($peakHour);
        $message .= "• Peak hour: {$peakTime}\n\n";

        return $message;
    }

    /**
     * Get insights section
     */
    private function getInsights(): string
    {
        $insights = $this->analytics->getInsights($this->user);

        if (empty($insights)) {
            return '';
        }

        $message = "💡 *Insights:*\n";

        foreach (array_slice($insights, 0, 3) as $insight) {
            $message .= "• {$insight['description']}\n";
        }

        $message .= "\n";

        return $message;
    }

    /**
     * Get records section
     */
    private function getRecords(): string
    {
        $records = $this->analytics->getRecords($this->user);

        if (empty($records)) {
            return '';
        }

        $message = "🏆 *Records:*\n";

        if (isset($records['highest_expense'])) {
            $record = $records['highest_expense'];
            $amount = ExpenseFormatter::formatAmount($record['amount']);
            $message .= "• Highest expense: {$amount} ({$record['category']})\n";
        }

        if (isset($records['favorite_merchant'])) {
            $merchant = $records['favorite_merchant'];
            $message .= "• Favorite merchant: {$merchant['name']} ({$merchant['count']} times)\n";
        }

        if (isset($records['most_active_day'])) {
            $day = $records['most_active_day'];
            $message .= "• Most active day: {$day['date']} ({$day['count']} expenses)\n";
        }

        $message .= "\n";

        return $message;
    }

    /**
     * Get prediction section
     */
    private function getPrediction(): string
    {
        $prediction = $this->analytics->predictNextMonthSpending($this->user);

        if ($prediction['prediction'] <= 0) {
            return '';
        }

        $message = "🔮 *Next Month Prediction:*\n";

        $amount = ExpenseFormatter::formatAmount($prediction['prediction']);
        $confidence = ucfirst($prediction['confidence']);

        $message .= "• Expected spending: {$amount}\n";
        $message .= "• Confidence: {$confidence}\n";

        if ($prediction['growth_rate'] != 0) {
            $trend = ExpenseFormatter::formatPercentageChange($prediction['growth_rate']);
            $message .= "• Trend: {$trend}\n";
        }

        return $message;
    }

    /**
     * Format hour range for display
     */
    private function formatHourRange(int $hour): string
    {
        $start = str_pad($hour, 2, '0', STR_PAD_LEFT).':00';
        $end = str_pad($hour + 1, 2, '0', STR_PAD_LEFT).':00';

        // Add context
        if ($hour >= 6 && $hour < 12) {
            return "{$start}-{$end} (morning)";
        } elseif ($hour >= 12 && $hour < 14) {
            return "{$start}-{$end} (lunch)";
        } elseif ($hour >= 14 && $hour < 19) {
            return "{$start}-{$end} (afternoon)";
        } elseif ($hour >= 19 && $hour < 22) {
            return "{$start}-{$end} (evening)";
        } else {
            return "{$start}-{$end}";
        }
    }
}
