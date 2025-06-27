<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ExpenseAnalyticsService
{
    /**
     * Get daily average spending for a user
     */
    public function getDailyAverage(User $user, Carbon $startDate, Carbon $endDate): float
    {
        $total = $user->expenses()
            ->whereBetween('expense_date', [$startDate, $endDate])
            ->where('status', 'confirmed')
            ->sum('amount');

        $days = $startDate->diffInDays($endDate) + 1;

        return $days > 0 ? $total / $days : 0;
    }

    /**
     * Get weekly average spending
     */
    public function getWeeklyAverage(User $user, int $weeks = 4): float
    {
        $endDate = Carbon::now()->endOfDay();
        $startDate = $endDate->copy()->subWeeks($weeks)->startOfDay();

        $total = $user->expenses()
            ->whereBetween('expense_date', [$startDate, $endDate])
            ->where('status', 'confirmed')
            ->sum('amount');

        return $weeks > 0 ? $total / $weeks : 0;
    }

    /**
     * Get monthly average spending
     */
    public function getMonthlyAverage(User $user, int $months = 6): float
    {
        $endDate = Carbon::now()->endOfMonth();
        $startDate = $endDate->copy()->subMonths($months - 1)->startOfMonth();

        $total = $user->expenses()
            ->whereBetween('expense_date', [$startDate, $endDate])
            ->where('status', 'confirmed')
            ->sum('amount');

        return $months > 0 ? $total / $months : 0;
    }

    /**
     * Get spending by day of week
     */
    public function getSpendingByDayOfWeek(User $user, int $weeks = 8): array
    {
        $endDate = Carbon::now()->endOfDay();
        $startDate = $endDate->copy()->subWeeks($weeks)->startOfDay();

        $expenses = $user->expenses()
            ->select(
                DB::raw('EXTRACT(DOW FROM expense_date) as day_of_week'),
                DB::raw('AVG(amount) as average'),
                DB::raw('COUNT(*) as count'),
                DB::raw('SUM(amount) as total')
            )
            ->whereBetween('expense_date', [$startDate, $endDate])
            ->where('status', 'confirmed')
            ->groupBy('day_of_week')
            ->orderBy('day_of_week')
            ->get();

        $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        $result = [];

        foreach ($days as $index => $day) {
            $dayData = $expenses->firstWhere('day_of_week', $index);
            $result[$day] = [
                'average' => $dayData ? round($dayData->average, 2) : 0,
                'total' => $dayData ? round($dayData->total, 2) : 0,
                'count' => $dayData ? $dayData->count : 0,
            ];
        }

        return $result;
    }

    /**
     * Get spending by hour of day
     */
    public function getSpendingByHour(User $user, int $days = 30): array
    {
        $endDate = Carbon::now()->endOfDay();
        $startDate = $endDate->copy()->subDays($days)->startOfDay();

        $expenses = $user->expenses()
            ->select(
                DB::raw('EXTRACT(HOUR FROM created_at) as hour'),
                DB::raw('COUNT(*) as count'),
                DB::raw('SUM(amount) as total')
            )
            ->whereBetween('created_at', [$startDate, $endDate])
            ->where('status', 'confirmed')
            ->groupBy('hour')
            ->orderBy('hour')
            ->get()
            ->keyBy('hour');

        $result = [];
        for ($hour = 0; $hour < 24; $hour++) {
            $data = $expenses->get($hour);
            $result[$hour] = [
                'count' => $data ? $data->count : 0,
                'total' => $data ? round($data->total, 2) : 0,
            ];
        }

        return $result;
    }

    /**
     * Get top categories by spending
     */
    public function getTopCategories(User $user, Carbon $startDate, Carbon $endDate, int $limit = 5): Collection
    {
        return $user->expenses()
            ->select(
                'categories.name as category_name',
                'categories.id as category_id',
                DB::raw('COALESCE(parent.name, categories.name) as parent_category'),
                DB::raw('SUM(expenses.amount) as total'),
                DB::raw('COUNT(expenses.id) as count')
            )
            ->join('categories', 'expenses.category_id', '=', 'categories.id')
            ->leftJoin('categories as parent', 'categories.parent_id', '=', 'parent.id')
            ->whereBetween('expense_date', [$startDate, $endDate])
            ->where('expenses.status', 'confirmed')
            ->groupBy('categories.id', 'categories.name', 'parent.name')
            ->orderByDesc('total')
            ->limit($limit)
            ->get();
    }

    /**
     * Get spending trends (month over month)
     */
    public function getMonthlyTrends(User $user, int $months = 6): array
    {
        $trends = [];
        $endDate = Carbon::now()->endOfMonth();

        for ($i = $months - 1; $i >= 0; $i--) {
            $monthStart = $endDate->copy()->subMonths($i)->startOfMonth();
            $monthEnd = $monthStart->copy()->endOfMonth();

            $total = $user->expenses()
                ->whereBetween('expense_date', [$monthStart, $monthEnd])
                ->where('status', 'confirmed')
                ->sum('amount');

            $trends[] = [
                'month' => $monthStart->format('Y-m'),
                'month_name' => ucfirst($monthStart->translatedFormat('F')),
                'total' => round($total, 2),
                'start_date' => $monthStart->toDateString(),
                'end_date' => $monthEnd->toDateString(),
            ];
        }

        // Calculate growth rates
        for ($i = 1; $i < count($trends); $i++) {
            $previous = $trends[$i - 1]['total'];
            $current = $trends[$i]['total'];

            if ($previous > 0) {
                $trends[$i]['growth'] = round((($current - $previous) / $previous) * 100, 1);
            } else {
                $trends[$i]['growth'] = $current > 0 ? 100 : 0;
            }
        }

        return $trends;
    }

    /**
     * Get expense insights
     */
    public function getInsights(User $user): array
    {
        $insights = [];

        // Cache key for user insights
        $cacheKey = "insights_user_{$user->id}";

        return Cache::remember($cacheKey, 3600, function () use ($user) {
            $insights = [];

            // Most expensive day of the week
            $daySpending = $this->getSpendingByDayOfWeek($user);
            $maxDay = array_keys($daySpending, max($daySpending))[0];
            $insights[] = [
                'type' => 'spending_pattern',
                'title' => 'Highest Spending Day',
                'description' => "You tend to spend the most on {$maxDay}s",
                'value' => $daySpending[$maxDay]['average'],
            ];

            // Peak spending hour
            $hourSpending = $this->getSpendingByHour($user);
            $peakHour = array_search(max(array_column($hourSpending, 'count')), array_column($hourSpending, 'count'));
            $insights[] = [
                'type' => 'time_pattern',
                'title' => 'Peak Expense Time',
                'description' => "Most expenses recorded around {$peakHour}:00",
                'value' => $hourSpending[$peakHour]['count'],
            ];

            // Category trends
            $currentMonth = Carbon::now()->startOfMonth();
            $lastMonth = $currentMonth->copy()->subMonth();

            $currentCategories = $this->getTopCategories($user, $currentMonth, $currentMonth->copy()->endOfMonth(), 3);
            $lastCategories = $this->getTopCategories($user, $lastMonth, $lastMonth->copy()->endOfMonth(), 3);

            foreach ($currentCategories as $category) {
                $lastMonthData = $lastCategories->firstWhere('category_id', $category->category_id);
                if ($lastMonthData) {
                    $change = (($category->total - $lastMonthData->total) / $lastMonthData->total) * 100;
                    if (abs($change) > 20) {
                        $insights[] = [
                            'type' => 'category_change',
                            'title' => $change > 0 ? 'Increased Spending' : 'Reduced Spending',
                            'description' => "{$category->parent_category} ".($change > 0 ? 'up' : 'down').' '.abs(round($change)).'% this month',
                            'value' => $change,
                        ];
                    }
                }
            }

            return $insights;
        });
    }

    /**
     * Get expense records (highest, most frequent, etc.)
     */
    public function getRecords(User $user): array
    {
        $records = [];

        // Highest single expense
        $highest = $user->expenses()
            ->where('status', 'confirmed')
            ->orderByDesc('amount')
            ->first();

        if ($highest) {
            $records['highest_expense'] = [
                'amount' => $highest->amount,
                'description' => $highest->description,
                'date' => $highest->expense_date->format('d/m/Y'),
                'category' => $highest->category->name,
            ];
        }

        // Most frequent merchant (from descriptions)
        $merchants = $user->expenses()
            ->where('status', 'confirmed')
            ->whereNotNull('merchant')
            ->select('merchant', DB::raw('COUNT(*) as count'))
            ->groupBy('merchant')
            ->orderByDesc('count')
            ->first();

        if ($merchants) {
            $records['favorite_merchant'] = [
                'name' => $merchants->merchant,
                'count' => $merchants->count,
            ];
        }

        // Most active day
        $activeDay = $user->expenses()
            ->where('status', 'confirmed')
            ->select(
                DB::raw('DATE(expense_date) as date'),
                DB::raw('COUNT(*) as count'),
                DB::raw('SUM(amount) as total')
            )
            ->groupBy('date')
            ->orderByDesc('count')
            ->first();

        if ($activeDay) {
            $records['most_active_day'] = [
                'date' => Carbon::parse($activeDay->date)->format('d/m/Y'),
                'count' => $activeDay->count,
                'total' => $activeDay->total,
            ];
        }

        return $records;
    }

    /**
     * Predict next month's spending based on trends
     */
    public function predictNextMonthSpending(User $user): array
    {
        $trends = $this->getMonthlyTrends($user, 6);

        if (count($trends) < 3) {
            return [
                'prediction' => 0,
                'confidence' => 'low',
                'method' => 'insufficient_data',
            ];
        }

        // Simple moving average
        $recentMonths = array_slice($trends, -3);
        $average = array_sum(array_column($recentMonths, 'total')) / 3;

        // Calculate trend
        $lastMonth = end($trends);
        $previousMonth = $trends[count($trends) - 2];
        $growthRate = $lastMonth['growth'] ?? 0;

        // Apply growth rate to average
        $prediction = $average * (1 + ($growthRate / 100));

        // Determine confidence based on variance
        $variance = $this->calculateVariance(array_column($trends, 'total'));
        $confidence = $variance < 0.2 ? 'high' : ($variance < 0.5 ? 'medium' : 'low');

        return [
            'prediction' => round($prediction, 2),
            'confidence' => $confidence,
            'method' => 'moving_average',
            'based_on_months' => 3,
            'growth_rate' => $growthRate,
        ];
    }

    /**
     * Calculate variance coefficient
     */
    private function calculateVariance(array $values): float
    {
        $count = count($values);
        if ($count < 2) {
            return 0;
        }

        $mean = array_sum($values) / $count;
        $variance = 0;

        foreach ($values as $value) {
            $variance += pow($value - $mean, 2);
        }

        $variance = sqrt($variance / $count);

        return $mean > 0 ? $variance / $mean : 0;
    }
}
