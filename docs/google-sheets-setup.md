# Google Sheets Setup for ExpenseBot

## Prerequisites

1. A Google Account
2. Google Cloud Project with billing enabled
3. Google Sheets API enabled

## Setup Steps

### 1. Create Google Sheets Spreadsheet

1. Go to [Google Sheets](https://sheets.google.com)
2. Create a new spreadsheet
3. Name it "ExpenseBot Data" or similar
4. Copy the spreadsheet ID from the URL:
   - URL format: `https://docs.google.com/spreadsheets/d/SPREADSHEET_ID/edit`
   - The SPREADSHEET_ID is the long string between `/d/` and `/edit`

### 2. Enable Google Sheets API

1. Go to [Google Cloud Console](https://console.cloud.google.com)
2. Select your project
3. Navigate to "APIs & Services" → "Library"
4. Search for "Google Sheets API"
5. Click on it and press "Enable"

### 3. Create Service Account for Sheets

You can use the same service account as Google Cloud Vision/Speech, or create a separate one:

1. Go to "IAM & Admin" → "Service Accounts"
2. Click "Create Service Account" (or use existing)
3. Name: "expensebot-sheets-service"
4. Grant role: "Editor" (for Sheets access)
5. Create and download JSON key

### 4. Share Spreadsheet with Service Account

1. Open your Google Sheets spreadsheet
2. Click "Share" button
3. Add the service account email (found in the JSON file as `client_email`)
4. Give "Editor" permissions
5. Click "Send"

### 5. Configure ExpenseBot

Add to your `.env` file:

```env
# Google Sheets Configuration
GOOGLE_SHEETS_ENABLED=true
GOOGLE_SHEETS_CREDENTIALS_PATH=/path/to/sheets-credentials.json
GOOGLE_SHEETS_ID=your_spreadsheet_id_here
```

If using the same credentials as Google Cloud:
```env
GOOGLE_SHEETS_CREDENTIALS_PATH=/path/to/google-cloud-credentials.json
```

### 6. Initialize Spreadsheet Structure

Run the initialization command:

```bash
php artisan sheets:init
```

This will create the following sheets:
- **Expenses**: Main expense data
- **Category Summary**: Totals by category
- **Monthly Trends**: Month-over-month analysis
- **Settings**: Configuration data

## Sheet Structure

### Expenses Sheet
| Date | Amount | Currency | Description | Category | Subcategory | Method | Confidence | ID |
|------|--------|----------|-------------|----------|-------------|---------|------------|-----|

### Category Summary Sheet
| Category | Current Month | Last Month | Change % |
|----------|---------------|------------|----------|

### Monthly Trends Sheet
| Month | Total | Food | Transport | Shopping | Entertainment | Health | Bills | Other |
|-------|-------|------|-----------|----------|---------------|---------|-------|-------|

## Troubleshooting

### "Permission denied" errors
- Ensure the service account email has Editor access to the spreadsheet
- Check that the credentials file path is correct
- Verify the service account has the necessary API permissions

### "Spreadsheet not found"
- Double-check the GOOGLE_SHEETS_ID in your .env file
- Ensure the spreadsheet hasn't been deleted
- Verify the service account has access

### Sync not working
- Check Laravel logs: `tail -f storage/logs/laravel.log`
- Ensure GOOGLE_SHEETS_ENABLED=true
- Verify queue workers are running
- Check if the job is failing: `php artisan queue:failed`

### Testing the connection
```bash
# Test Google Sheets connection
php artisan tinker
>>> $sheets = new \App\Services\GoogleSheetsService();
>>> $sheets->initialize();
>>> $sheets->testConnection();
```

## Manual Sync

If you need to manually sync an expense:

```bash
php artisan tinker
>>> $expense = \App\Models\Expense::find(123);
>>> \App\Jobs\SyncExpenseToGoogleSheets::dispatch($expense);
```

## Security Notes

- Never commit the credentials JSON file
- Use restrictive file permissions (600)
- Consider using Google Cloud Secret Manager for production
- Regularly rotate service account keys