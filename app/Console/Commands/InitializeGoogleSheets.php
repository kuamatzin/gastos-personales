<?php

namespace App\Console\Commands;

use App\Services\GoogleSheetsService;
use Exception;
use Illuminate\Console\Command;

class InitializeGoogleSheets extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sheets:init 
                            {--force : Force recreation of sheets structure}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Initialize Google Sheets structure for expense tracking';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Initializing Google Sheets structure...');

        // Check if Google Sheets is enabled
        if (! config('services.google_sheets.enabled')) {
            $this->error('Google Sheets integration is disabled. Enable it in your .env file.');

            return Command::FAILURE;
        }

        // Check if credentials exist
        $credentialsPath = config('services.google_sheets.credentials_path');
        if (! file_exists($credentialsPath)) {
            $this->error("Google Sheets credentials not found at: {$credentialsPath}");
            $this->line('Please ensure you have placed your service account credentials JSON file at the specified location.');

            return Command::FAILURE;
        }

        // Check if spreadsheet ID is configured
        if (! config('services.google_sheets.spreadsheet_id')) {
            $this->error('Google Sheets ID not configured. Please set GOOGLE_SHEETS_ID in your .env file.');

            return Command::FAILURE;
        }

        try {
            $sheets = new GoogleSheetsService;

            $this->line('Connecting to Google Sheets...');
            $sheets->initialize();

            $this->info('✓ Connected successfully!');

            $this->line('Creating sheet structure...');

            // The initialize method already ensures sheet structure
            $this->info('✓ Sheet structure created/verified!');

            // Display summary
            $this->newLine();
            $this->info('Google Sheets initialization completed successfully!');

            $this->table(
                ['Sheet Name', 'Purpose'],
                [
                    ['Expenses', 'Main expense records with all details'],
                    ['Category Summary', 'Monthly spending by category with comparisons'],
                    ['Monthly Trends', 'Historical spending trends over 12 months'],
                    ['Settings', 'Configuration and metadata storage'],
                ]
            );

            $this->newLine();
            $this->line('Next steps:');
            $this->line('1. Your spreadsheet is now ready to receive expense data');
            $this->line('2. Expenses will sync automatically when created/updated');
            $this->line('3. Run "php artisan sheets:sync-historical" to import existing data');

            return Command::SUCCESS;

        } catch (Exception $e) {
            $this->error('Failed to initialize Google Sheets: '.$e->getMessage());

            if (strpos($e->getMessage(), '404') !== false) {
                $this->line('The spreadsheet ID appears to be invalid. Please check your GOOGLE_SHEETS_ID configuration.');
            } elseif (strpos($e->getMessage(), '403') !== false) {
                $this->line('Permission denied. Please ensure:');
                $this->line('1. The service account has access to the spreadsheet');
                $this->line('2. The spreadsheet is shared with the service account email');
            }

            return Command::FAILURE;
        }
    }
}
