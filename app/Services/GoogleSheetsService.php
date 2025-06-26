<?php

namespace App\Services;

use Google\Client;
use Google\Service\Sheets;
use Google\Service\Sheets\ValueRange;
use Google\Service\Sheets\BatchUpdateSpreadsheetRequest;
use Google\Service\Sheets\Request as SheetsRequest;
use App\Models\Expense;
use App\Models\Category;
use Illuminate\Support\Facades\Log;
use Exception;
use Illuminate\Support\Carbon;

class GoogleSheetsService
{
    private ?Client $client = null;
    private ?Sheets $service = null;
    private ?string $spreadsheetId = null;
    
    private const SHEET_EXPENSES = 'Expenses';
    private const SHEET_CATEGORY_SUMMARY = 'Category Summary';
    private const SHEET_MONTHLY_TRENDS = 'Monthly Trends';
    private const SHEET_SETTINGS = 'Settings';
    
    private const EXPENSE_COLUMNS = [
        'Date', 'Amount', 'Currency', 'Description', 
        'Category', 'Subcategory', 'Method', 'Confidence', 'ID'
    ];
    
    public function __construct()
    {
        $this->spreadsheetId = config('services.google_sheets.spreadsheet_id');
    }
    
    /**
     * Initialize Google Sheets client and service
     */
    public function initialize(): void
    {
        if (!config('services.google_sheets.enabled')) {
            return;
        }
        
        try {
            $this->client = new Client();
            $this->client->setAuthConfig(config('services.google_sheets.credentials_path'));
            $this->client->addScope(Sheets::SPREADSHEETS);
            $this->client->setApplicationName('ExpenseBot');
            
            $this->service = new Sheets($this->client);
            
            $this->ensureSheetStructure();
        } catch (Exception $e) {
            Log::error('Failed to initialize Google Sheets', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * Ensure all required sheets exist with proper structure
     */
    public function ensureSheetStructure(): void
    {
        try {
            $spreadsheet = $this->service->spreadsheets->get($this->spreadsheetId);
            $existingSheets = array_map(
                fn($sheet) => $sheet->getProperties()->getTitle(),
                $spreadsheet->getSheets()
            );
            
            $requiredSheets = [
                self::SHEET_EXPENSES,
                self::SHEET_CATEGORY_SUMMARY,
                self::SHEET_MONTHLY_TRENDS,
                self::SHEET_SETTINGS
            ];
            
            $sheetsToCreate = array_diff($requiredSheets, $existingSheets);
            
            if (!empty($sheetsToCreate)) {
                $this->createSheets($sheetsToCreate);
            }
            
            // Ensure headers are set
            $this->ensureHeaders();
            
        } catch (Exception $e) {
            Log::error('Failed to ensure sheet structure', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * Create missing sheets
     */
    private function createSheets(array $sheetNames): void
    {
        $requests = [];
        
        foreach ($sheetNames as $sheetName) {
            $requests[] = [
                'addSheet' => [
                    'properties' => [
                        'title' => $sheetName
                    ]
                ]
            ];
        }
        
        $batchUpdateRequest = new BatchUpdateSpreadsheetRequest([
            'requests' => $requests
        ]);
        
        $this->service->spreadsheets->batchUpdate(
            $this->spreadsheetId,
            $batchUpdateRequest
        );
    }
    
    /**
     * Ensure headers are set for all sheets
     */
    private function ensureHeaders(): void
    {
        // Set headers for Expenses sheet
        $expenseHeaders = [[
            'Date', 'Amount', 'Currency', 'Description',
            'Category', 'Subcategory', 'Method', 'Confidence', 'ID'
        ]];
        
        $this->updateRange(self::SHEET_EXPENSES . '!A1:I1', $expenseHeaders);
        
        // Set headers for Category Summary
        $categoryHeaders = [[
            'Category', 'This Month', 'Last Month', 'Change %'
        ]];
        
        $this->updateRange(self::SHEET_CATEGORY_SUMMARY . '!A1:D1', $categoryHeaders);
        
        // Set headers for Monthly Trends
        $trendHeaders = [[
            'Month', 'Food', 'Transport', 'Shopping', 
            'Entertainment', 'Health', 'Bills', 'Other', 'Total'
        ]];
        
        $this->updateRange(self::SHEET_MONTHLY_TRENDS . '!A1:I1', $trendHeaders);
        
        // Set headers for Settings
        $settingsHeaders = [[
            'Setting', 'Value', 'Last Updated'
        ]];
        
        $this->updateRange(self::SHEET_SETTINGS . '!A1:C1', $settingsHeaders);
        
        // Apply formatting
        $this->formatSheet();
    }
    
    /**
     * Append a new expense to the sheet
     */
    public function appendExpense(Expense $expense): void
    {
        if (!$this->isInitialized()) {
            $this->initialize();
        }
        
        try {
            $values = [[
                $expense->expense_date->format('Y-m-d'),
                $expense->amount,
                $expense->currency,
                $expense->description,
                $expense->category->parent->name ?? $expense->category->name,
                $expense->category->parent ? $expense->category->name : '',
                $expense->inference_method ?? 'manual',
                $expense->category_confidence ?? 1.0,
                $expense->id
            ]];
            
            $body = new ValueRange([
                'values' => $values
            ]);
            
            $params = [
                'valueInputOption' => 'USER_ENTERED'
            ];
            
            $this->service->spreadsheets_values->append(
                $this->spreadsheetId,
                self::SHEET_EXPENSES . '!A:I',
                $body,
                $params
            );
            
            // Update summaries
            $this->updateMonthlyTotals();
            
        } catch (Exception $e) {
            Log::error('Failed to append expense to sheets', [
                'expense_id' => $expense->id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * Update monthly totals and category summaries
     */
    public function updateMonthlyTotals(): void
    {
        try {
            $currentMonth = Carbon::now()->startOfMonth();
            $lastMonth = $currentMonth->copy()->subMonth();
            
            // Get totals for current and last month
            $currentTotals = $this->getCategoryTotals($currentMonth, $currentMonth->copy()->endOfMonth());
            $lastTotals = $this->getCategoryTotals($lastMonth, $lastMonth->copy()->endOfMonth());
            
            // Update Category Summary sheet
            $summaryData = [];
            
            foreach ($currentTotals as $category => $amount) {
                $lastAmount = $lastTotals[$category] ?? 0;
                $change = $lastAmount > 0 
                    ? round((($amount - $lastAmount) / $lastAmount) * 100, 1)
                    : 0;
                
                $summaryData[] = [
                    $category,
                    $amount,
                    $lastAmount,
                    $change . '%'
                ];
            }
            
            // Clear existing data and write new
            $this->clearRange(self::SHEET_CATEGORY_SUMMARY . '!A2:D100');
            
            if (!empty($summaryData)) {
                $body = new ValueRange([
                    'values' => $summaryData
                ]);
                
                $this->service->spreadsheets_values->update(
                    $this->spreadsheetId,
                    self::SHEET_CATEGORY_SUMMARY . '!A2',
                    $body,
                    ['valueInputOption' => 'USER_ENTERED']
                );
            }
            
            // Update Monthly Trends
            $this->updateMonthlyTrends();
            
        } catch (Exception $e) {
            Log::error('Failed to update monthly totals', [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Get category totals for a date range
     */
    public function getCategoryTotals(Carbon $startDate, Carbon $endDate): array
    {
        $expenses = Expense::with('category.parent')
            ->whereBetween('expense_date', [$startDate, $endDate])
            ->where('status', 'confirmed')
            ->get();
        
        $totals = [];
        
        foreach ($expenses as $expense) {
            $categoryName = $expense->category->parent->name ?? $expense->category->name;
            
            if (!isset($totals[$categoryName])) {
                $totals[$categoryName] = 0;
            }
            
            $totals[$categoryName] += $expense->amount;
        }
        
        return $totals;
    }
    
    /**
     * Update monthly trends data
     */
    private function updateMonthlyTrends(): void
    {
        try {
            // Get last 12 months of data
            $trends = [];
            $endDate = Carbon::now()->endOfMonth();
            
            for ($i = 11; $i >= 0; $i--) {
                $monthStart = $endDate->copy()->subMonths($i)->startOfMonth();
                $monthEnd = $monthStart->copy()->endOfMonth();
                
                $monthTotals = $this->getCategoryTotals($monthStart, $monthEnd);
                
                $trend = [
                    $monthStart->format('Y-m'),
                    $monthTotals['Food'] ?? 0,
                    $monthTotals['Transport'] ?? 0,
                    $monthTotals['Shopping'] ?? 0,
                    $monthTotals['Entertainment'] ?? 0,
                    $monthTotals['Health'] ?? 0,
                    $monthTotals['Bills'] ?? 0,
                    $monthTotals['Other'] ?? 0,
                    array_sum($monthTotals)
                ];
                
                $trends[] = $trend;
            }
            
            // Clear existing data and write new
            $this->clearRange(self::SHEET_MONTHLY_TRENDS . '!A2:I100');
            
            if (!empty($trends)) {
                $body = new ValueRange([
                    'values' => $trends
                ]);
                
                $this->service->spreadsheets_values->update(
                    $this->spreadsheetId,
                    self::SHEET_MONTHLY_TRENDS . '!A2',
                    $body,
                    ['valueInputOption' => 'USER_ENTERED']
                );
            }
            
        } catch (Exception $e) {
            Log::error('Failed to update monthly trends', [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Format the sheet with proper styling
     */
    public function formatSheet(): void
    {
        try {
            $requests = [
                // Format headers - bold and colored background
                [
                    'repeatCell' => [
                        'range' => [
                            'sheetId' => $this->getSheetId(self::SHEET_EXPENSES),
                            'startRowIndex' => 0,
                            'endRowIndex' => 1
                        ],
                        'cell' => [
                            'userEnteredFormat' => [
                                'backgroundColor' => [
                                    'red' => 0.2,
                                    'green' => 0.2,
                                    'blue' => 0.2
                                ],
                                'textFormat' => [
                                    'foregroundColor' => [
                                        'red' => 1.0,
                                        'green' => 1.0,
                                        'blue' => 1.0
                                    ],
                                    'bold' => true
                                ]
                            ]
                        ],
                        'fields' => 'userEnteredFormat(backgroundColor,textFormat)'
                    ]
                ],
                // Freeze header row
                [
                    'updateSheetProperties' => [
                        'properties' => [
                            'sheetId' => $this->getSheetId(self::SHEET_EXPENSES),
                            'gridProperties' => [
                                'frozenRowCount' => 1
                            ]
                        ],
                        'fields' => 'gridProperties.frozenRowCount'
                    ]
                ],
                // Auto-resize columns
                [
                    'autoResizeDimensions' => [
                        'dimensions' => [
                            'sheetId' => $this->getSheetId(self::SHEET_EXPENSES),
                            'dimension' => 'COLUMNS',
                            'startIndex' => 0,
                            'endIndex' => 9
                        ]
                    ]
                ]
            ];
            
            $batchUpdateRequest = new BatchUpdateSpreadsheetRequest([
                'requests' => $requests
            ]);
            
            $this->service->spreadsheets->batchUpdate(
                $this->spreadsheetId,
                $batchUpdateRequest
            );
            
        } catch (Exception $e) {
            Log::error('Failed to format sheet', [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Get sheet ID by name
     */
    private function getSheetId(string $sheetName): ?int
    {
        try {
            $spreadsheet = $this->service->spreadsheets->get($this->spreadsheetId);
            
            foreach ($spreadsheet->getSheets() as $sheet) {
                if ($sheet->getProperties()->getTitle() === $sheetName) {
                    return $sheet->getProperties()->getSheetId();
                }
            }
            
            return null;
        } catch (Exception $e) {
            Log::error('Failed to get sheet ID', [
                'sheet_name' => $sheetName,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
    
    /**
     * Update a specific range with values
     */
    private function updateRange(string $range, array $values): void
    {
        try {
            $body = new ValueRange([
                'values' => $values
            ]);
            
            $this->service->spreadsheets_values->update(
                $this->spreadsheetId,
                $range,
                $body,
                ['valueInputOption' => 'USER_ENTERED']
            );
        } catch (Exception $e) {
            Log::error('Failed to update range', [
                'range' => $range,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Clear a specific range
     */
    private function clearRange(string $range): void
    {
        try {
            $this->service->spreadsheets_values->clear(
                $this->spreadsheetId,
                $range
            );
        } catch (Exception $e) {
            Log::error('Failed to clear range', [
                'range' => $range,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Check if service is initialized
     */
    private function isInitialized(): bool
    {
        return $this->client !== null && $this->service !== null;
    }
    
    /**
     * Sync all historical expenses to sheets
     */
    public function syncHistoricalData(): void
    {
        if (!$this->isInitialized()) {
            $this->initialize();
        }
        
        try {
            // Clear existing data
            $this->clearRange(self::SHEET_EXPENSES . '!A2:I');
            
            // Get all confirmed expenses
            $expenses = Expense::with('category.parent')
                ->where('status', 'confirmed')
                ->orderBy('expense_date')
                ->get();
            
            $values = [];
            
            foreach ($expenses as $expense) {
                $values[] = [
                    $expense->expense_date->format('Y-m-d'),
                    $expense->amount,
                    $expense->currency,
                    $expense->description,
                    $expense->category->parent->name ?? $expense->category->name,
                    $expense->category->parent ? $expense->category->name : '',
                    $expense->inference_method ?? 'manual',
                    $expense->category_confidence ?? 1.0,
                    $expense->id
                ];
            }
            
            if (!empty($values)) {
                // Batch update in chunks of 1000
                $chunks = array_chunk($values, 1000);
                
                foreach ($chunks as $chunk) {
                    $body = new ValueRange([
                        'values' => $chunk
                    ]);
                    
                    $this->service->spreadsheets_values->append(
                        $this->spreadsheetId,
                        self::SHEET_EXPENSES . '!A:I',
                        $body,
                        ['valueInputOption' => 'USER_ENTERED']
                    );
                }
            }
            
            // Update summaries
            $this->updateMonthlyTotals();
            
            Log::info('Historical data synced to Google Sheets', [
                'expense_count' => count($expenses)
            ]);
            
        } catch (Exception $e) {
            Log::error('Failed to sync historical data', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
}