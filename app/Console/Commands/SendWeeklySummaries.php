<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Services\TelegramService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class SendWeeklySummaries extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'summaries:send-weekly {--user=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send weekly expense summaries to users';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Sending weekly expense summaries...');
        
        $query = User::where('is_active', true)
            ->whereNotNull('telegram_id')
            ->where('preferences->notifications->weekly_summary', true);
        
        // If specific user is requested
        if ($userId = $this->option('user')) {
            $query->where('id', $userId);
        }
        
        $users = $query->get();
        
        $this->info("Found {$users->count()} users with weekly summaries enabled");
        
        $sent = 0;
        $failed = 0;
        
        foreach ($users as $user) {
            try {
                $this->info("Processing user #{$user->id}: {$user->name}");
                
                if ($this->sendWeeklySummary($user)) {
                    $sent++;
                    $this->info("✓ Weekly summary sent to user #{$user->id}");
                } else {
                    $this->info("⚠ No expenses this week for user #{$user->id}");
                }
                
            } catch (\Exception $e) {
                $failed++;
                $this->error("✗ Failed to send weekly summary to user #{$user->id}: {$e->getMessage()}");
                
                Log::error('Failed to send weekly summary', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }
        
        $this->info("\nWeekly summaries complete!");
        $this->info("Sent: {$sent} summaries");
        if ($failed > 0) {
            $this->warn("Failed: {$failed} summaries");
        }
        
        return Command::SUCCESS;
    }
    
    /**
     * Send weekly summary to a user
     */
    private function sendWeeklySummary(User $user): bool
    {
        $telegram = app(TelegramService::class);
        $language = $user->language ?? 'es';
        $timezone = $user->getTimezone();
        
        $now = Carbon::now($timezone);
        $startOfWeek = $now->copy()->startOfWeek();
        $endOfWeek = $now->copy()->endOfWeek();
        
        // Get this week's expenses
        $expenses = $user->expenses()
            ->whereBetween('expense_date', [$startOfWeek, $endOfWeek])
            ->where('status', 'confirmed')
            ->with('category')
            ->orderBy('expense_date')
            ->orderBy('created_at', 'desc')
            ->get();
        
        if ($expenses->isEmpty()) {
            return false;
        }
        
        // Build summary message
        $message = $this->buildWeeklySummaryMessage($user, $expenses, $startOfWeek, $endOfWeek, $language);
        
        // Send message
        try {
            $telegram->sendMessage($user->telegram_id, $message, [
                'parse_mode' => 'Markdown',
            ]);
            
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send weekly summary via Telegram', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
    
    /**
     * Build the weekly summary message
     */
    private function buildWeeklySummaryMessage(User $user, $expenses, Carbon $startOfWeek, Carbon $endOfWeek, string $language): string
    {
        $dateRange = $startOfWeek->locale($language)->isoFormat('D [de] MMMM') . ' - ' . 
                     $endOfWeek->locale($language)->isoFormat('D [de] MMMM');
        
        $message = trans('telegram.weekly_summary_title', [
            'date_range' => $dateRange,
        ], $language) . "\n\n";
        
        // Calculate totals by category
        $categoryTotals = [];
        $dailyTotals = [];
        $total = 0;
        
        foreach ($expenses as $expense) {
            // Category totals
            $categoryName = $expense->category 
                ? $expense->category->getTranslatedName($language) 
                : trans('telegram.categories.uncategorized', [], $language);
            
            if (!isset($categoryTotals[$categoryName])) {
                $categoryTotals[$categoryName] = [
                    'amount' => 0,
                    'count' => 0,
                    'icon' => $expense->category->icon ?? '❓',
                ];
            }
            
            $categoryTotals[$categoryName]['amount'] += $expense->amount;
            $categoryTotals[$categoryName]['count']++;
            
            // Daily totals
            $day = $expense->expense_date->format('Y-m-d');
            if (!isset($dailyTotals[$day])) {
                $dailyTotals[$day] = 0;
            }
            $dailyTotals[$day] += $expense->amount;
            
            $total += $expense->amount;
        }
        
        // Sort categories by amount (descending)
        arsort($categoryTotals);
        
        // Add daily breakdown
        $message .= trans('telegram.weekly_summary_daily_breakdown', [], $language) . "\n";
        foreach ($dailyTotals as $date => $dayTotal) {
            $dayOfWeek = Carbon::parse($date)->locale($language)->isoFormat('dddd');
            $message .= sprintf(
                "• %s: $%s\n",
                ucfirst($dayOfWeek),
                number_format($dayTotal, 2)
            );
        }
        
        // Add category breakdown
        $message .= "\n" . trans('telegram.weekly_summary_by_category', [], $language) . "\n";
        foreach ($categoryTotals as $category => $data) {
            $percentage = round(($data['amount'] / $total) * 100);
            $message .= sprintf(
                "%s %s: $%s (%d%%) - %d %s\n",
                $data['icon'],
                $category,
                number_format($data['amount'], 2),
                $percentage,
                $data['count'],
                trans_choice('telegram.expenses_count', $data['count'], ['count' => $data['count']], $language)
            );
        }
        
        // Add total
        $message .= "\n" . trans('telegram.weekly_summary_total', [
            'amount' => number_format($total, 2),
            'count' => $expenses->count(),
        ], $language) . "\n";
        
        // Calculate daily average
        $daysWithExpenses = count($dailyTotals);
        $dailyAverage = $daysWithExpenses > 0 ? $total / $daysWithExpenses : 0;
        $message .= trans('telegram.weekly_summary_daily_average', [
            'amount' => number_format($dailyAverage, 2),
        ], $language) . "\n";
        
        // Compare with last week
        $lastWeekStart = $startOfWeek->copy()->subWeek();
        $lastWeekEnd = $endOfWeek->copy()->subWeek();
        $lastWeekTotal = $user->expenses()
            ->whereBetween('expense_date', [$lastWeekStart, $lastWeekEnd])
            ->where('status', 'confirmed')
            ->sum('amount');
        
        if ($lastWeekTotal > 0) {
            $difference = $total - $lastWeekTotal;
            $percentChange = round(($difference / $lastWeekTotal) * 100);
            
            if ($difference > 0) {
                $message .= "\n📈 " . trans('telegram.weekly_summary_increase', [
                    'percent' => abs($percentChange),
                    'amount' => number_format(abs($difference), 2),
                ], $language);
            } elseif ($difference < 0) {
                $message .= "\n📉 " . trans('telegram.weekly_summary_decrease', [
                    'percent' => abs($percentChange),
                    'amount' => number_format(abs($difference), 2),
                ], $language);
            } else {
                $message .= "\n➡️ " . trans('telegram.weekly_summary_same', [], $language);
            }
        }
        
        // Find the most expensive day
        if (!empty($dailyTotals)) {
            $maxDay = array_search(max($dailyTotals), $dailyTotals);
            $maxDayName = Carbon::parse($maxDay)->locale($language)->isoFormat('dddd');
            $message .= "\n\n💸 " . trans('telegram.weekly_summary_most_expensive_day', [
                'day' => ucfirst($maxDayName),
                'amount' => number_format(max($dailyTotals), 2),
            ], $language);
        }
        
        // Add insights based on spending patterns
        if ($total < $dailyAverage * 5) {
            $message .= "\n\n🎯 " . trans('telegram.weekly_summary_good_week', [], $language);
        } elseif (count($categoryTotals) > 10) {
            $message .= "\n\n💡 " . trans('telegram.weekly_summary_diverse_spending', [], $language);
        }
        
        // Add subscription reminder if user has active subscriptions
        $activeSubscriptions = $user->subscriptions()->where('status', 'active')->count();
        if ($activeSubscriptions > 0) {
            $message .= "\n\n🔄 " . trans('telegram.weekly_summary_subscription_reminder', [
                'count' => $activeSubscriptions,
            ], $language);
        }
        
        return $message;
    }
}