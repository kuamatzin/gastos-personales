# Task 02: Telegram Bot Integration

## Objective
Set up Telegram bot integration with webhook endpoint and basic command handling.

## Prerequisites
- Database setup completed (Task 01)
- Telegram Bot Token from BotFather
- HTTPS endpoint for webhook (use ngrok for local development)

## Deliverables

### 1. Environment Configuration
Add to `.env`:
```env
TELEGRAM_BOT_TOKEN=your_bot_token_here
TELEGRAM_WEBHOOK_SECRET=random_secret_string
```

### 2. Create Telegram Service

#### app/Services/TelegramService.php
Implement core Telegram functionality:
- `setWebhook()` - Register webhook with Telegram
- `sendMessage()` - Send text messages
- `sendMessageWithKeyboard()` - Send messages with inline keyboards
- `sendExpenseConfirmation()` - Send expense confirmation with actions
- `sendCategorySelection()` - Display category selection interface
- `deleteMessage()` - Remove messages
- `editMessage()` - Update existing messages

### 3. Create Webhook Controller

#### app/Http/Controllers/TelegramWebhookController.php
Handle incoming updates:
- Text messages
- Callback queries (button presses)
- Voice messages
- Photo messages
- Commands (/start, /help)

### 4. Create Telegram Command Handlers

#### app/Telegram/Commands/StartCommand.php
- Welcome message
- Register user if not exists
- Show available commands

#### app/Telegram/Commands/HelpCommand.php
- Display usage instructions
- Show example inputs
- List available commands

### 5. Update Routes

#### routes/api.php
```php
Route::post('/telegram/webhook', [TelegramWebhookController::class, 'handle'])
    ->name('telegram.webhook');
```

### 6. Disable CSRF for Webhook
Update `app/Http/Middleware/VerifyCsrfToken.php`:
```php
protected $except = [
    'api/telegram/webhook'
];
```

### 7. Create Webhook Setup Command

#### app/Console/Commands/SetupTelegramWebhook.php
Artisan command to register webhook:
```bash
php artisan telegram:webhook:set
```

### 8. Basic Message Router

Create message routing logic to:
- Detect command messages (/start, /help)
- Route text messages to expense processing
- Handle callback queries for confirmations
- Queue voice and image messages for processing

## Implementation Details

### Webhook Security
- Verify webhook secret in middleware
- Validate Telegram update structure
- Log all incoming updates for debugging

### User Registration Flow
1. User sends /start
2. Extract Telegram user data
3. Create or update User record with telegram_id
4. Send welcome message with instructions

### Basic Text Processing Flow
1. Receive text message
2. Validate user exists and is active
3. Queue ProcessExpenseText job
4. Send "Processing..." message to user

## Commands
```bash
# Create service provider
php artisan make:provider TelegramServiceProvider

# Create controller
php artisan make:controller TelegramWebhookController

# Create commands
php artisan make:command SetupTelegramWebhook

# Set webhook (after implementation)
php artisan telegram:webhook:set
```

## Testing with ngrok
```bash
# Start ngrok
ngrok http 8000

# Update .env with ngrok URL
TELEGRAM_WEBHOOK_URL=https://your-subdomain.ngrok.io

# Set webhook
php artisan telegram:webhook:set
```

## Success Criteria
- Webhook successfully registered with Telegram
- Bot responds to /start and /help commands
- User registration works correctly
- Text messages are queued for processing
- Basic error handling implemented