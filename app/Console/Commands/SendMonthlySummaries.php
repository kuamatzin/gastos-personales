<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Services\TelegramService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class SendMonthlySummaries extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'summaries:send-monthly {--user=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send monthly expense summaries to users';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Sending monthly expense summaries...');
        
        // Get current hour and day
        $currentHour = Carbon::now('America/Mexico_City')->hour;
        $currentDay = Carbon::now('America/Mexico_City')->day;
        
        // Determine if it's the last day of the month
        $isLastDay = Carbon::now('America/Mexico_City')->day === Carbon::now('America/Mexico_City')->daysInMonth;
        
        // Only run on the last day of the month
        if (!$isLastDay && !$this->option('user')) {
            $this->info('Not the last day of the month, skipping monthly summaries');
            return Command::SUCCESS;
        }
        
        $query = User::where('is_active', true)
            ->whereNotNull('telegram_id')
            ->where('preferences->notifications->monthly_summary', true);
        
        // If specific user is requested (for testing)
        if ($userId = $this->option('user')) {
            $query->where('id', $userId);
        } else {
            // Filter by users who want their summary at this hour
            $query->where('preferences->notifications->monthly_summary_time', $currentHour);
        }
        
        $users = $query->get();
        
        $this->info("Found {$users->count()} users with monthly summaries enabled for hour {$currentHour}:00");
        
        $sent = 0;
        $failed = 0;
        
        foreach ($users as $user) {
            try {
                $this->info("Processing user #{$user->id}: {$user->name}");
                
                if ($this->sendMonthlySummary($user)) {
                    $sent++;
                    $this->info("✓ Monthly summary sent to user #{$user->id}");
                } else {
                    $this->info("⚠ No expenses this month for user #{$user->id}");
                }
                
            } catch (\Exception $e) {
                $failed++;
                $this->error("✗ Failed to send monthly summary to user #{$user->id}: {$e->getMessage()}");
                
                Log::error('Failed to send monthly summary', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }
        
        $this->info("\nMonthly summaries complete!");
        $this->info("Sent: {$sent} summaries");
        if ($failed > 0) {
            $this->warn("Failed: {$failed} summaries");
        }
        
        return Command::SUCCESS;
    }
    
    /**
     * Send monthly summary to a user
     */
    private function sendMonthlySummary(User $user): bool
    {
        $telegram = app(TelegramService::class);
        $language = $user->language ?? 'es';
        $timezone = $user->getTimezone();
        
        $now = Carbon::now($timezone);
        $startOfMonth = $now->copy()->startOfMonth();
        $endOfMonth = $now->copy()->endOfMonth();
        
        // Get this month's expenses
        $expenses = $user->expenses()
            ->whereBetween('expense_date', [$startOfMonth, $endOfMonth])
            ->where('status', 'confirmed')
            ->with('category')
            ->orderBy('expense_date')
            ->orderBy('created_at', 'desc')
            ->get();
        
        if ($expenses->isEmpty()) {
            return false;
        }
        
        // Build summary message
        $message = $this->buildMonthlySummaryMessage($user, $expenses, $startOfMonth, $endOfMonth, $language);
        
        // Send message
        try {
            $telegram->sendMessage($user->telegram_id, $message, [
                'parse_mode' => 'Markdown',
            ]);
            
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send monthly summary via Telegram', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
    
    /**
     * Build the monthly summary message
     */
    private function buildMonthlySummaryMessage(User $user, $expenses, Carbon $startOfMonth, Carbon $endOfMonth, string $language): string
    {
        $monthYear = $startOfMonth->locale($language)->isoFormat('MMMM Y');
        
        $message = trans('telegram.monthly_summary_title', [
            'month' => $monthYear,
        ], $language) . "\n\n";
        
        // Calculate totals by category and week
        $categoryTotals = [];
        $weeklyTotals = [];
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
            
            // Weekly totals
            $weekNumber = $expense->expense_date->weekOfMonth;
            if (!isset($weeklyTotals[$weekNumber])) {
                $weeklyTotals[$weekNumber] = 0;
            }
            $weeklyTotals[$weekNumber] += $expense->amount;
            
            $total += $expense->amount;
        }
        
        // Sort categories by amount (descending)
        arsort($categoryTotals);
        
        // Add weekly breakdown
        $message .= trans('telegram.monthly_summary_weekly_breakdown', [], $language) . "\n";
        foreach ($weeklyTotals as $week => $weekTotal) {
            $message .= sprintf(
                "• %s %d: $%s\n",
                trans('telegram.week', [], $language),
                $week,
                number_format($weekTotal, 2)
            );
        }
        
        // Add top categories (limit to top 10)
        $message .= "\n" . trans('telegram.monthly_summary_top_categories', [], $language) . "\n";
        $topCategories = array_slice($categoryTotals, 0, 10, true);
        foreach ($topCategories as $category => $data) {
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
        $message .= "\n" . trans('telegram.monthly_summary_total', [
            'amount' => number_format($total, 2),
            'count' => $expenses->count(),
        ], $language) . "\n";
        
        // Calculate daily average
        $daysInMonth = $startOfMonth->daysInMonth;
        $dailyAverage = $total / $daysInMonth;
        $message .= trans('telegram.monthly_summary_daily_average', [
            'amount' => number_format($dailyAverage, 2),
        ], $language) . "\n";
        
        // Compare with last month
        $lastMonthStart = $startOfMonth->copy()->subMonth();
        $lastMonthEnd = $endOfMonth->copy()->subMonth();
        $lastMonthTotal = $user->expenses()
            ->whereBetween('expense_date', [$lastMonthStart, $lastMonthEnd])
            ->where('status', 'confirmed')
            ->sum('amount');
        
        if ($lastMonthTotal > 0) {
            $difference = $total - $lastMonthTotal;
            $percentChange = round(($difference / $lastMonthTotal) * 100);
            
            if ($difference > 0) {
                $message .= "\n📈 " . trans('telegram.monthly_summary_increase', [
                    'percent' => abs($percentChange),
                    'amount' => number_format(abs($difference), 2),
                ], $language);
            } elseif ($difference < 0) {
                $message .= "\n📉 " . trans('telegram.monthly_summary_decrease', [
                    'percent' => abs($percentChange),
                    'amount' => number_format(abs($difference), 2),
                ], $language);
            } else {
                $message .= "\n➡️ " . trans('telegram.monthly_summary_same', [], $language);
            }
        }
        
        // Find the most expensive expense
        $mostExpensive = $expenses->sortByDesc('amount')->first();
        if ($mostExpensive) {
            $message .= "\n\n💸 " . trans('telegram.monthly_summary_most_expensive', [
                'description' => $mostExpensive->description,
                'amount' => number_format($mostExpensive->amount, 2),
                'date' => $mostExpensive->expense_date->format('d/m'),
            ], $language);
        }
        
        // Add insights based on spending patterns
        if ($total < $dailyAverage * 25) {
            $message .= "\n\n🎯 " . trans('telegram.monthly_summary_good_month', [], $language);
        } elseif (count($categoryTotals) > 15) {
            $message .= "\n\n💡 " . trans('telegram.monthly_summary_diverse_spending', [], $language);
        }
        
        // Add installment and subscription information
        $activeInstallments = $user->installmentPlans()->where('status', 'active')->count();
        $activeSubscriptions = $user->subscriptions()->where('status', 'active')->count();
        
        if ($activeInstallments > 0 || $activeSubscriptions > 0) {
            $message .= "\n\n📊 " . trans('telegram.monthly_summary_recurring_payments', [], $language) . "\n";
            
            if ($activeInstallments > 0) {
                $installmentTotal = $user->installmentPlans()
                    ->where('status', 'active')
                    ->sum('monthly_amount');
                $message .= "💳 " . trans('telegram.monthly_summary_installments', [
                    'count' => $activeInstallments,
                    'amount' => number_format($installmentTotal, 2),
                ], $language) . "\n";
            }
            
            if ($activeSubscriptions > 0) {
                // Calculate estimated monthly subscription cost
                $subscriptionTotal = 0;
                $subscriptions = $user->subscriptions()->where('status', 'active')->get();
                foreach ($subscriptions as $subscription) {
                    $monthlyAmount = $subscription->getMonthlyEquivalent();
                    $subscriptionTotal += $monthlyAmount;
                }
                
                $message .= "🔄 " . trans('telegram.monthly_summary_subscriptions', [
                    'count' => $activeSubscriptions,
                    'amount' => number_format($subscriptionTotal, 2),
                ], $language) . "\n";
            }
        }
        
        // Add year-to-date information
        $yearStart = Carbon::now($user->getTimezone())->startOfYear();
        $yearTotal = $user->expenses()
            ->whereBetween('expense_date', [$yearStart, $endOfMonth])
            ->where('status', 'confirmed')
            ->sum('amount');
        
        $message .= "\n📅 " . trans('telegram.monthly_summary_year_to_date', [
            'amount' => number_format($yearTotal, 2),
            'year' => $yearStart->year,
        ], $language);
        
        return $message;
    }
}