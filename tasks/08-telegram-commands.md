# Task 08: Telegram Commands and Analytics

## Objective
Implement Telegram bot commands for expense queries and analytics.

## Prerequisites
- Basic Telegram integration (Task 02)
- Database with expense data
- Google Sheets integration (optional for some features)

## Deliverables

### 1. Create Command Router

#### app/Telegram/CommandRouter.php
Route commands to appropriate handlers:
```php
class CommandRouter
{
    protected $commands = [
        '/start' => StartCommand::class,
        '/help' => HelpCommand::class,
        '/expenses_today' => ExpensesTodayCommand::class,
        '/expenses_month' => ExpensesMonthCommand::class,
        '/expenses_week' => ExpensesWeekCommand::class,
        '/category_spending' => CategorySpendingCommand::class,
        '/top_categories' => TopCategoriesCommand::class,
        '/export' => ExportCommand::class,
        '/stats' => StatsCommand::class,
        '/cancel' => CancelCommand::class,
    ];
}
```

### 2. Implement Query Commands

#### app/Telegram/Commands/ExpensesTodayCommand.php
Show today's expenses:
```
💰 Today's Expenses (26/06/2025)

🍽️ Food & Dining: $235.50
🚗 Transportation: $45.00
🛍️ Shopping: $189.99

📊 Total: $470.49 MXN
📈 3 expenses recorded
```

#### app/Telegram/Commands/ExpensesMonthCommand.php
Show current month summary with comparison:
```
📅 June 2025 Expenses

🍽️ Food & Dining: $3,450.00 (+12%)
🚗 Transportation: $890.00 (-5%)
🛍️ Shopping: $2,100.00 (+25%)
🎬 Entertainment: $650.00 (0%)

📊 Total: $7,090.00 MXN
📈 vs Last Month: +8.5%
💡 45 expenses recorded
```

#### app/Telegram/Commands/CategorySpendingCommand.php
Detailed category breakdown:
```
🏷️ Category Spending - June 2025

🍽️ Food & Dining: $3,450.00 (48.6%)
  └ Restaurants: $1,200.00
  └ Groceries: $1,500.00
  └ Coffee: $450.00
  └ Delivery: $300.00

Choose a category for details:
[Food] [Transport] [Shopping] [More...]
```

### 3. Implement Export Commands

#### app/Telegram/Commands/ExportCommand.php
Export options:
```
📤 Export Expenses

Select format:
[📊 Excel] [📄 PDF] [💾 CSV]

Select period:
[This Month] [Last Month] [Custom Range]
```

Generate and send file through Telegram.

### 4. Create Statistics Command

#### app/Telegram/Commands/StatsCommand.php
Show insights and trends:
```
📈 Expense Statistics

🔥 Spending Trends:
- Highest day: Tuesday ($850 avg)
- Lowest day: Sunday ($120 avg)
- Peak hour: 1-2 PM (lunch)

💡 Insights:
- Food spending up 15% this month
- Transport costs stable
- New category detected: Gym

🏆 Records:
- Highest expense: $2,500 (Electronics)
- Most frequent: Coffee (12 times)
- Favorite merchant: Starbucks

📊 View detailed analytics: /analytics
```

### 5. Create Interactive Analytics

#### app/Services/ExpenseAnalyticsService.php
Calculate various metrics:
- Daily/weekly/monthly averages
- Category trends
- Spending patterns
- Predictive analysis

### 6. Implement Inline Queries

Support inline queries for quick searches:
```
@ExpenseBot uber
> Uber - Transportation: $145 (10 Jun)
> Uber Eats - Food: $235 (8 Jun)
> Uber - Transportation: $89 (5 Jun)
```

### 7. Create Notification System

#### app/Services/NotificationService.php
Proactive notifications:
- Weekly summaries
- Monthly reports
- Unusual spending alerts

### 8. Implement Command Pagination

For commands returning large datasets:
```php
class PaginatedResponse
{
    public function render($data, $page, $perPage)
    {
        // Create inline keyboard with pagination
        // [← Previous] [Page 2/5] [Next →]
    }
}
```

### 9. Add Command Help System

Enhanced help with examples:
```
/help category_spending
```
Shows:
```
📊 Category Spending Command

Shows spending breakdown by category.

Usage:
/category_spending - Current month
/category_spending <month> - Specific month
/category_spending <category> - Category details

Examples:
/category_spending
/category_spending january
/category_spending food

Related: /top_categories, /stats
```

## Implementation Patterns

### Command Base Class
```php
abstract class Command
{
    protected $telegram;
    protected $user;
    
    abstract public function handle($message, $params = []);
    
    protected function reply($text, $options = [])
    {
        return $this->telegram->sendMessage(
            $this->user->telegram_id,
            $text,
            $options
        );
    }
}
```

### Caching Strategy
Cache frequently requested data:
- Daily totals (1 hour)
- Monthly summaries (6 hours)
- Category totals (1 hour)

### Response Formatting
```php
class ExpenseFormatter
{
    public function formatAmount($amount, $currency = 'MXN')
    {
        return '$' . number_format($amount, 2) . ' ' . $currency;
    }
    
    public function formatPercentage($value, $total)
    {
        return round(($value / $total) * 100, 1) . '%';
    }
}
```

## Success Criteria
- All commands respond within 2 seconds
- Data is accurate and up-to-date
- Formatting is consistent and readable
- Export files are properly formatted
- Analytics provide actionable insights