# Task 06: Google Sheets Integration

## Objective
Implement Google Sheets integration for expense backup and user-friendly analysis.

## Prerequisites
- Google Cloud project with Sheets API enabled
- Service account credentials
- Expense processing system working (Task 05)

## Deliverables

### 1. Environment Configuration
Add to `.env`:
```env
GOOGLE_SHEETS_CREDENTIALS_PATH=storage/app/google-credentials.json
GOOGLE_SHEETS_ID=your_spreadsheet_id_here
GOOGLE_SHEETS_ENABLED=true
```

### 2. Install Google Client Library
```bash
composer require google/apiclient:^2.12
```

### 3. Create Google Sheets Service

#### app/Services/GoogleSheetsService.php
Core functionality:
- `initialize()` - Set up Google client and sheets service
- `ensureSheetStructure()` - Create required sheets if missing
- `appendExpense()` - Add new expense row
- `updateMonthlyTotals()` - Update category summaries
- `getCategoryTotals()` - Retrieve spending by category
- `formatSheet()` - Apply formatting and formulas

### 4. Define Sheet Structure

#### Sheet 1: Expenses
| Date | Amount | Currency | Description | Category | Subcategory | Method | Confidence | ID |
|------|--------|----------|-------------|----------|-------------|---------|------------|-----|

#### Sheet 2: Category Summary
| Category | This Month | Last Month | Change % |
|----------|------------|------------|----------|

#### Sheet 3: Monthly Trends
| Month | Food | Transport | Shopping | Entertainment | Health | Bills | Other |
|-------|------|-----------|----------|---------------|---------|-------|-------|

#### Sheet 4: Settings
| Setting | Value | Last Updated |
|---------|-------|--------------|

### 5. Create Sync Job

#### app/Jobs/SyncExpenseToSheets.php
```php
class SyncExpenseToSheets implements ShouldQueue
{
    public $tries = 5;
    public $backoff = [60, 120, 300]; // Exponential backoff
    
    protected $expense;
    
    public function handle(GoogleSheetsService $sheets)
    {
        // Append expense data
        // Update category totals
        // Update monthly trends
    }
}
```

### 6. Implement Sheet Formatting

#### Apply formatting:
- Date columns: DD/MM/YYYY
- Amount columns: Currency format
- Headers: Bold, colored background
- Frozen header row
- Auto-resize columns
- Conditional formatting for budgets

### 7. Create Sheet Management Commands

#### app/Console/Commands/InitializeGoogleSheets.php
```bash
php artisan sheets:init
```
- Create spreadsheet structure
- Set up formulas
- Apply initial formatting

#### app/Console/Commands/SyncHistoricalData.php
```bash
php artisan sheets:sync-historical
```
- Sync existing expenses to sheets
- Rebuild category summaries

### 8. Implement Read Operations

For analytics and reports:
- `getExpensesByDateRange()`
- `getCategorySpending()`
- `getMonthlyTrends()`

### 9. Error Handling

Handle common issues:
- API rate limits (implement exponential backoff)
- Network timeouts
- Invalid credentials
- Sheet structure changes
- Quota exceeded errors

### 10. Create Observer for Automatic Sync

#### app/Observers/ExpenseObserver.php
```php
class ExpenseObserver
{
    public function created(Expense $expense)
    {
        if ($expense->status === 'confirmed') {
            SyncExpenseToSheets::dispatch($expense);
        }
    }
}
```

## Implementation Example

```php
class GoogleSheetsService
{
    private $client;
    private $service;
    private $spreadsheetId;
    
    public function appendExpense(Expense $expense)
    {
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
        
        $body = new Google_Service_Sheets_ValueRange([
            'values' => $values
        ]);
        
        $params = [
            'valueInputOption' => 'USER_ENTERED'
        ];
        
        $this->service->spreadsheets_values->append(
            $this->spreadsheetId,
            'Expenses!A:I',
            $body,
            $params
        );
    }
}
```

## Security Considerations

1. **Service Account**
   - Use service account instead of OAuth
   - Restrict permissions to specific spreadsheet
   - Store credentials securely

2. **Data Privacy**
   - Don't expose spreadsheet ID publicly
   - Implement access controls
   - Consider encryption for sensitive data

## Testing Strategy

### Unit Tests
- Mock Google Sheets API
- Test data formatting
- Verify error handling

### Integration Tests
- Test with real API (limited)
- Verify data persistence
- Test quota handling

## Performance Optimization

1. **Batch Operations**
   - Group multiple updates
   - Use batch append when possible

2. **Caching**
   - Cache category totals
   - Cache monthly summaries
   - Invalidate on updates

3. **Queue Management**
   - Use separate queue for sheets sync
   - Implement rate limiting
   - Handle failures gracefully

## Success Criteria
- Expenses sync within 1 minute of confirmation
- Sheet structure maintained automatically
- Category summaries update correctly
- No data loss during sync failures
- Formulas and formatting preserved