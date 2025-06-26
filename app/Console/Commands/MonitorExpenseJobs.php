<?php

namespace App\Console\Commands;

use App\Models\Expense;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

class MonitorExpenseJobs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'expense:monitor 
                          {--failed : Show only failed jobs}
                          {--pending : Show only pending expenses}
                          {--stats : Show statistics}
                          {--user= : Filter by user telegram ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Monitor expense processing jobs and status';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        if ($this->option('stats')) {
            $this->showStatistics();
            return;
        }

        if ($this->option('failed')) {
            $this->showFailedJobs();
            return;
        }

        if ($this->option('pending')) {
            $this->showPendingExpenses();
            return;
        }

        // Default: show overview
        $this->showOverview();
    }

    /**
     * Show overview of expense processing
     */
    protected function showOverview(): void
    {
        $this->info('📊 Expense Processing Overview');
        $this->newLine();

        // Queue status
        $queueSize = Queue::size('default');
        $this->info("📬 Queue Size: {$queueSize} jobs");

        // Failed jobs count
        $failedCount = DB::table('failed_jobs')
            ->where('payload', 'like', '%ProcessExpense%')
            ->count();
        
        if ($failedCount > 0) {
            $this->warn("❌ Failed Jobs: {$failedCount}");
        }

        // Expense status breakdown
        $statusCounts = Expense::select('status', DB::raw('count(*) as count'))
            ->when($this->option('user'), function ($query) {
                $user = User::where('telegram_id', $this->option('user'))->first();
                return $query->where('user_id', $user?->id);
            })
            ->groupBy('status')
            ->get()
            ->pluck('count', 'status')
            ->toArray();

        $this->newLine();
        $this->info('📈 Expense Status Breakdown:');
        
        $statuses = [
            'pending' => '⏳ Pending',
            'confirmed' => '✅ Confirmed',
            'auto_confirmed' => '🤖 Auto-confirmed',
            'rejected' => '❌ Rejected',
            'needs_review' => '👀 Needs Review',
        ];

        foreach ($statuses as $status => $label) {
            $count = $statusCounts[$status] ?? 0;
            $this->info("  {$label}: {$count}");
        }

        // Recent activity
        $this->newLine();
        $this->info('🕐 Recent Activity (Last 10):');
        
        $recentExpenses = Expense::with(['user', 'category'])
            ->when($this->option('user'), function ($query) {
                $user = User::where('telegram_id', $this->option('user'))->first();
                return $query->where('user_id', $user?->id);
            })
            ->latest()
            ->limit(10)
            ->get();

        $this->table(
            ['ID', 'User', 'Amount', 'Description', 'Status', 'Created'],
            $recentExpenses->map(function ($expense) {
                return [
                    $expense->id,
                    $expense->user->name,
                    '$' . number_format($expense->amount, 2) . ' ' . $expense->currency,
                    substr($expense->description, 0, 30) . '...',
                    $this->formatStatus($expense->status),
                    $expense->created_at->diffForHumans(),
                ];
            })
        );
    }

    /**
     * Show detailed statistics
     */
    protected function showStatistics(): void
    {
        $this->info('📊 Expense Processing Statistics');
        $this->newLine();

        // Overall stats
        $stats = DB::table('expenses')
            ->when($this->option('user'), function ($query) {
                $user = User::where('telegram_id', $this->option('user'))->first();
                return $query->where('user_id', $user?->id);
            })
            ->selectRaw('
                COUNT(*) as total,
                AVG(confidence_score) as avg_confidence,
                AVG(category_confidence) as avg_category_confidence,
                SUM(CASE WHEN status = "confirmed" THEN 1 ELSE 0 END) as confirmed_count,
                SUM(CASE WHEN status = "auto_confirmed" THEN 1 ELSE 0 END) as auto_confirmed_count,
                SUM(CASE WHEN status = "rejected" THEN 1 ELSE 0 END) as rejected_count
            ')
            ->first();

        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Expenses', number_format($stats->total)],
                ['Confirmed', number_format($stats->confirmed_count)],
                ['Auto-confirmed', number_format($stats->auto_confirmed_count)],
                ['Rejected', number_format($stats->rejected_count)],
                ['Avg. Confidence', round($stats->avg_confidence * 100, 1) . '%'],
                ['Avg. Category Confidence', round($stats->avg_category_confidence * 100, 1) . '%'],
            ]
        );

        // Processing by input type
        $this->newLine();
        $this->info('📱 By Input Type:');
        
        $byType = DB::table('expenses')
            ->when($this->option('user'), function ($query) {
                $user = User::where('telegram_id', $this->option('user'))->first();
                return $query->where('user_id', $user?->id);
            })
            ->select('input_type', DB::raw('count(*) as count'))
            ->groupBy('input_type')
            ->get();

        $this->table(
            ['Type', 'Count'],
            $byType->map(function ($row) {
                return [
                    ucfirst($row->input_type),
                    number_format($row->count),
                ];
            })
        );

        // Top categories
        $this->newLine();
        $this->info('🏷️ Top Categories:');
        
        $topCategories = DB::table('expenses')
            ->join('categories', 'expenses.category_id', '=', 'categories.id')
            ->when($this->option('user'), function ($query) {
                $user = User::where('telegram_id', $this->option('user'))->first();
                return $query->where('expenses.user_id', $user?->id);
            })
            ->select('categories.name', 'categories.icon', DB::raw('count(*) as count'))
            ->groupBy('categories.id', 'categories.name', 'categories.icon')
            ->orderByDesc('count')
            ->limit(10)
            ->get();

        $this->table(
            ['Category', 'Count'],
            $topCategories->map(function ($row) {
                return [
                    ($row->icon ?? '📋') . ' ' . $row->name,
                    number_format($row->count),
                ];
            })
        );
    }

    /**
     * Show failed jobs
     */
    protected function showFailedJobs(): void
    {
        $this->info('❌ Failed Expense Processing Jobs');
        $this->newLine();

        $failedJobs = DB::table('failed_jobs')
            ->where('payload', 'like', '%ProcessExpense%')
            ->latest()
            ->limit(20)
            ->get();

        if ($failedJobs->isEmpty()) {
            $this->info('No failed expense processing jobs found! 🎉');
            return;
        }

        $this->table(
            ['ID', 'Job', 'Failed At', 'Exception'],
            $failedJobs->map(function ($job) {
                $payload = json_decode($job->payload, true);
                $jobClass = basename($payload['displayName'] ?? 'Unknown');
                $exception = substr($job->exception, 0, 50) . '...';
                
                return [
                    $job->id,
                    $jobClass,
                    $job->failed_at,
                    $exception,
                ];
            })
        );

        $this->newLine();
        $this->info('💡 To retry failed jobs, run: php artisan queue:retry all');
    }

    /**
     * Show pending expenses
     */
    protected function showPendingExpenses(): void
    {
        $this->info('⏳ Pending Expenses');
        $this->newLine();

        $query = Expense::with(['user', 'category'])
            ->pending();

        if ($this->option('user')) {
            $user = User::where('telegram_id', $this->option('user'))->first();
            $query->where('user_id', $user?->id);
        }

        $pendingExpenses = $query->latest()->limit(20)->get();

        if ($pendingExpenses->isEmpty()) {
            $this->info('No pending expenses found!');
            return;
        }

        $this->table(
            ['ID', 'User', 'Amount', 'Description', 'Category', 'Confidence', 'Age'],
            $pendingExpenses->map(function ($expense) {
                $categoryName = $expense->category 
                    ? ($expense->category->icon ?? '📋') . ' ' . $expense->category->name
                    : 'None';
                
                return [
                    $expense->id,
                    $expense->user->name,
                    '$' . number_format($expense->amount, 2),
                    substr($expense->description, 0, 25) . '...',
                    $categoryName,
                    round($expense->category_confidence * 100) . '%',
                    $expense->created_at->diffForHumans(),
                ];
            })
        );

        $this->newLine();
        $this->warn('⚠️ Expenses pending for over 1 hour may need manual review.');
    }

    /**
     * Format status for display
     */
    protected function formatStatus(string $status): string
    {
        return match($status) {
            'pending' => '⏳ Pending',
            'confirmed' => '✅ Confirmed',
            'auto_confirmed' => '🤖 Auto-confirmed',
            'rejected' => '❌ Rejected',
            'needs_review' => '👀 Needs Review',
            default => ucfirst($status),
        };
    }
}