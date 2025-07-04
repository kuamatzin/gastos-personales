<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Services\TelegramService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class SendDailySummaries extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'summaries:send-daily {--user=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send daily expense summaries to users';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Sending daily expense summaries...');
        
        $query = User::where('is_active', true)
            ->whereNotNull('telegram_id')
            ->where('preferences->notifications->daily_summary', true);
        
        // If specific user is requested
        if ($userId = $this->option('user')) {
            $query->where('id', $userId);
        }
        
        $users = $query->get();
        
        $this->info("Found {$users->count()} users with daily summaries enabled");
        
        $sent = 0;
        $failed = 0;
        
        foreach ($users as $user) {
            try {
                // Check if it's the right time for this user
                if (!$this->isTimeToSend($user)) {
                    $this->info("Skipping user #{$user->id} - not their notification time yet");
                    continue;
                }
                
                $this->info("Processing user #{$user->id}: {$user->name}");
                
                if ($this->sendDailySummary($user)) {
                    $sent++;
                    $this->info("✓ Summary sent to user #{$user->id}");
                } else {
                    $this->info("⚠ No expenses today for user #{$user->id}");
                }
                
            } catch (\Exception $e) {
                $failed++;
                $this->error("✗ Failed to send summary to user #{$user->id}: {$e->getMessage()}");
                
                Log::error('Failed to send daily summary', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }
        
        $this->info("\nDaily summaries complete!");
        $this->info("Sent: {$sent} summaries");
        if ($failed > 0) {
            $this->warn("Failed: {$failed} summaries");
        }
        
        return Command::SUCCESS;
    }
    
    /**
     * Check if it's time to send summary for this user
     */
    private function isTimeToSend(User $user): bool
    {
        // If running with specific user option, always send
        if ($this->option('user')) {
            return true;
        }
        
        $userTimezone = $user->getTimezone();
        $notificationHour = $user->preferences['notifications']['daily_summary_time'] ?? 21; // Default 9 PM
        
        $now = Carbon::now($userTimezone);
        $currentHour = $now->hour;
        
        // Send if current hour matches notification hour
        return $currentHour == $notificationHour;
    }
    
    /**
     * Send daily summary to a user
     */
    private function sendDailySummary(User $user): bool
    {
        $telegram = app(TelegramService::class);
        $language = $user->language ?? 'es';
        $timezone = $user->getTimezone();
        
        $today = Carbon::now($timezone);
        $startOfDay = $today->copy()->startOfDay();
        $endOfDay = $today->copy()->endOfDay();
        
        // Get today's expenses
        $expenses = $user->expenses()
            ->whereBetween('expense_date', [$startOfDay, $endOfDay])
            ->where('status', 'confirmed')
            ->with('category')
            ->orderBy('created_at', 'desc')
            ->get();
        
        if ($expenses->isEmpty()) {
            return false;
        }
        
        // Build summary message
        $message = $this->buildSummaryMessage($user, $expenses, $today, $language);
        
        // Send message
        try {
            $telegram->sendMessage($user->telegram_id, $message, [
                'parse_mode' => 'Markdown',
            ]);
            
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send daily summary via Telegram', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
    
    /**
     * Build the summary message
     */
    private function buildSummaryMessage(User $user, $expenses, Carbon $date, string $language): string
    {
        $dateFormatted = $date->locale($language)->isoFormat('dddd, D [de] MMMM');
        
        $message = trans('telegram.daily_summary_title', [
            'date' => ucfirst($dateFormatted),
        ], $language) . "\n\n";
        
        // Calculate totals by category
        $categoryTotals = [];
        $total = 0;
        
        foreach ($expenses as $expense) {
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
            $total += $expense->amount;
        }
        
        // Sort categories by amount (descending)
        arsort($categoryTotals);
        
        // Add category breakdown
        $message .= trans('telegram.daily_summary_by_category', [], $language) . "\n";
        foreach ($categoryTotals as $category => $data) {
            $percentage = round(($data['amount'] / $total) * 100);
            $message .= sprintf(
                "%s %s: $%s (%d%%) - %d %s\n",
                $data['icon'],
                $category,
                number_format($data['amount'], 2),
                $percentage,
                $data['count'],
                trans_choice('telegram.expenses_count', $data['count'], [], $language)
            );
        }
        
        // Add total
        $message .= "\n" . trans('telegram.daily_summary_total', [
            'amount' => number_format($total, 2),
            'count' => $expenses->count(),
        ], $language) . "\n";
        
        // Add comparison with yesterday
        $yesterday = $date->copy()->subDay();
        $yesterdayTotal = $user->expenses()
            ->whereDate('expense_date', $yesterday)
            ->where('status', 'confirmed')
            ->sum('amount');
        
        if ($yesterdayTotal > 0) {
            $difference = $total - $yesterdayTotal;
            $percentChange = round(($difference / $yesterdayTotal) * 100);
            
            if ($difference > 0) {
                $message .= "\n📈 " . trans('telegram.daily_summary_increase', [
                    'percent' => abs($percentChange),
                    'amount' => number_format(abs($difference), 2),
                ], $language);
            } elseif ($difference < 0) {
                $message .= "\n📉 " . trans('telegram.daily_summary_decrease', [
                    'percent' => abs($percentChange),
                    'amount' => number_format(abs($difference), 2),
                ], $language);
            } else {
                $message .= "\n➡️ " . trans('telegram.daily_summary_same', [], $language);
            }
        }
        
        // Add weekly average comparison
        $weekStart = $date->copy()->startOfWeek();
        $weekTotal = $user->expenses()
            ->whereBetween('expense_date', [$weekStart, $date])
            ->where('status', 'confirmed')
            ->sum('amount');
        
        $daysInWeek = $date->diffInDays($weekStart) + 1;
        $weeklyAverage = $weekTotal / $daysInWeek;
        
        if ($total > $weeklyAverage * 1.2) {
            $message .= "\n⚠️ " . trans('telegram.daily_summary_above_average', [
                'percent' => round((($total / $weeklyAverage) - 1) * 100),
            ], $language);
        }
        
        // Add motivational message based on spending
        if ($total < $weeklyAverage * 0.8) {
            $message .= "\n\n🎯 " . trans('telegram.daily_summary_good_job', [], $language);
        }
        
        return $message;
    }
}