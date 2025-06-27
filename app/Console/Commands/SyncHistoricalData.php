<?php

namespace App\Console\Commands;

use App\Models\Expense;
use App\Services\GoogleSheetsService;
use Exception;
use Illuminate\Console\Command;

class SyncHistoricalData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sheets:sync-historical 
                            {--from= : Start date for sync (Y-m-d format)}
                            {--to= : End date for sync (Y-m-d format)}
                            {--status=confirmed : Status of expenses to sync}
                            {--dry-run : Show what would be synced without actually syncing}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync historical expense data to Google Sheets';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Starting historical data sync to Google Sheets...');

        // Check if Google Sheets is enabled
        if (! config('services.google_sheets.enabled')) {
            $this->error('Google Sheets integration is disabled. Enable it in your .env file.');

            return Command::FAILURE;
        }

        // Parse date filters
        $fromDate = $this->option('from');
        $toDate = $this->option('to');
        $status = $this->option('status');
        $dryRun = $this->option('dry-run');

        // Build query
        $query = Expense::with('category.parent')
            ->where('status', $status)
            ->orderBy('expense_date');

        if ($fromDate) {
            try {
                $from = \Carbon\Carbon::parse($fromDate);
                $query->where('expense_date', '>=', $from);
                $this->line("Filtering from: {$from->format('Y-m-d')}");
            } catch (Exception $e) {
                $this->error('Invalid from date format. Use Y-m-d format.');

                return Command::FAILURE;
            }
        }

        if ($toDate) {
            try {
                $to = \Carbon\Carbon::parse($toDate);
                $query->where('expense_date', '<=', $to);
                $this->line("Filtering to: {$to->format('Y-m-d')}");
            } catch (Exception $e) {
                $this->error('Invalid to date format. Use Y-m-d format.');

                return Command::FAILURE;
            }
        }

        // Get expense count
        $totalExpenses = $query->count();

        if ($totalExpenses === 0) {
            $this->warn('No expenses found matching the criteria.');

            return Command::SUCCESS;
        }

        $this->info("Found {$totalExpenses} expenses to sync.");

        if ($dryRun) {
            $this->info('Dry run mode - no data will be synced.');

            // Show sample of expenses
            $sampleExpenses = $query->take(5)->get();

            $this->table(
                ['Date', 'Amount', 'Category', 'Description'],
                $sampleExpenses->map(function ($expense) {
                    return [
                        $expense->expense_date->format('Y-m-d'),
                        $expense->amount.' '.$expense->currency,
                        $expense->category->name ?? 'N/A',
                        \Str::limit($expense->description, 30),
                    ];
                })
            );

            if ($totalExpenses > 5) {
                $this->line('... and '.($totalExpenses - 5).' more expenses');
            }

            return Command::SUCCESS;
        }

        // Confirm action
        if (! $this->confirm("Do you want to sync {$totalExpenses} expenses to Google Sheets?")) {
            $this->info('Sync cancelled.');

            return Command::SUCCESS;
        }

        try {
            $sheets = new GoogleSheetsService;

            $this->line('Connecting to Google Sheets...');
            $sheets->initialize();

            $this->info('✓ Connected successfully!');

            // Perform sync with progress bar
            $this->line('Syncing expenses...');

            $progressBar = $this->output->createProgressBar($totalExpenses);
            $progressBar->start();

            // Process in chunks to avoid memory issues
            $synced = 0;
            $failed = 0;

            $query->chunk(100, function ($expenses) use ($sheets, &$synced, &$failed, $progressBar) {
                foreach ($expenses as $expense) {
                    try {
                        $sheets->appendExpense($expense);
                        $synced++;
                    } catch (Exception $e) {
                        $failed++;
                        $this->error("\nFailed to sync expense ID {$expense->id}: ".$e->getMessage());
                    }

                    $progressBar->advance();
                }
            });

            $progressBar->finish();
            $this->newLine(2);

            // Update category summaries and trends
            $this->line('Updating category summaries and monthly trends...');
            $sheets->updateMonthlyTotals();

            $this->info('✓ Summaries updated!');

            // Display results
            $this->newLine();
            $this->info('Historical sync completed!');

            $this->table(
                ['Metric', 'Count'],
                [
                    ['Total Expenses', $totalExpenses],
                    ['Successfully Synced', $synced],
                    ['Failed', $failed],
                ]
            );

            if ($failed > 0) {
                $this->warn('Some expenses failed to sync. Check the logs for details.');

                return Command::FAILURE;
            }

            return Command::SUCCESS;

        } catch (Exception $e) {
            $this->error('Failed to sync historical data: '.$e->getMessage());

            if (strpos($e->getMessage(), 'quota') !== false) {
                $this->line('You may have hit Google Sheets API quota limits.');
                $this->line('Try syncing smaller date ranges or wait before retrying.');
            }

            return Command::FAILURE;
        }
    }
}
