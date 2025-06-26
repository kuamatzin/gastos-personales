# ExpenseBot - Personal Expense Tracker with Telegram Integration

A Laravel-based expense tracking system that uses AI to automatically categorize expenses through a Telegram bot interface. Users can submit expenses via text, voice notes, or receipt photos.

## Features

- 🤖 **Telegram Bot Integration** - Submit expenses through Telegram
- 🎤 **Voice Recognition** - Send voice notes to record expenses
- 📸 **Receipt OCR** - Extract expense data from receipt photos
- 🧠 **AI Categorization** - Automatic expense categorization using OpenAI
- 📊 **Smart Learning** - Learns from user patterns for better categorization
- 📈 **Google Sheets Sync** - Export data for analysis
- 🌐 **Bilingual Support** - Works in English and Spanish

## Requirements

- PHP 8.1+
- Composer
- PostgreSQL or MySQL (SQLite for local development)
- Node.js & NPM
- Google Cloud Account (for OCR and Speech-to-Text)
- OpenAI API Key
- Telegram Bot Token

## Installation

1. **Clone the repository**
   ```bash
   git clone https://github.com/yourusername/gastos-personales.git
   cd gastos-personales
   ```

2. **Install dependencies**
   ```bash
   composer install
   npm install
   ```

3. **Configure environment**
   ```bash
   cp .env.example .env
   ```

4. **Set up environment variables**
   ```env
   # Telegram Configuration
   TELEGRAM_BOT_TOKEN=your_bot_token
   TELEGRAM_WEBHOOK_SECRET=your_webhook_secret

   # OpenAI Configuration
   OPENAI_API_KEY=your_openai_api_key

   # Google Cloud Configuration
   GOOGLE_CLOUD_CREDENTIALS_PATH=storage/app/google-cloud-credentials.json
   GOOGLE_CLOUD_PROJECT_ID=your_project_id

   # Google Sheets Configuration (optional)
   GOOGLE_SHEETS_CREDENTIALS_PATH=storage/app/google-credentials.json
   GOOGLE_SHEETS_ID=your_spreadsheet_id

   # Queue Configuration
   QUEUE_CONNECTION=database
   ```

5. **Generate application key**
   ```bash
   php artisan key:generate
   ```

6. **Run database migrations**
   ```bash
   php artisan migrate
   ```

7. **Seed categories**
   ```bash
   php artisan db:seed --class=CategorySeeder
   ```

## Local Development Setup

### 1. Start the development server
```bash
php artisan serve
```

### 2. Start the queue worker
```bash
php artisan queue:work --queue=high,default,low --tries=3
```

### 3. Set up ngrok for Telegram webhook
```bash
ngrok http 8000
```

### 4. Configure Telegram webhook
```bash
php artisan telegram:webhook:set https://your-ngrok-url.ngrok.io/api/telegram/webhook
```

### 5. Build frontend assets
```bash
npm run build
# or for development with hot reload
npm run dev
```

## Usage

### Telegram Bot Commands

- `/start` - Initialize the bot and see welcome message
- `/help` - View available commands
- `/expenses_today` - View today's expenses
- `/expenses_month` - View current month's expenses

### Submitting Expenses

1. **Text Message**
   - "Spent 150 pesos on Uber"
   - "Gasto 200 pesos en comida"
   - "Coffee $50"

2. **Voice Note**
   - Record a voice message describing your expense
   - Supports Spanish and English

3. **Receipt Photo**
   - Take a photo of your receipt
   - The bot will extract amount, merchant, and items

### Expense Confirmation Flow

1. Bot processes your expense and shows:
   - Amount and currency
   - Description
   - Suggested category with confidence level
   - Date and merchant (if applicable)

2. Available actions:
   - ✅ Confirm - Save the expense
   - ✏️ Edit Category - Choose a different category
   - 📝 Edit Description - Modify the description
   - ❌ Cancel - Discard the expense

## Testing

### Run the test suite
```bash
php artisan test
```

### Test OCR functionality
```bash
# Test with local image
php artisan test:ocr /path/to/receipt.jpg --parse

# Test with image URL
php artisan test:ocr-url https://example.com/receipt.jpg --parse
```

### Test Speech-to-Text
```bash
# Test with local audio file
php artisan test:speech /path/to/audio.mp3 --timestamps

# Test with audio URL
php artisan test:speech-url https://example.com/audio.mp3 --detect-language
```

## Production Deployment

### Using Laravel Forge

1. **Server Requirements**
   - Ubuntu 22.04 LTS
   - PHP 8.1+
   - MySQL/PostgreSQL

2. **Environment Configuration**
   ```env
   QUEUE_CONNECTION=database
   CACHE_DRIVER=database
   SESSION_DRIVER=database
   ```

3. **Queue Worker Setup**
   - In Forge, go to your site → Queue tab
   - Create new worker with:
     - Connection: `database`
     - Queue: `high,default,low`
     - Processes: 1-3 (based on load)

4. **SSL and Webhook**
   - Enable SSL (Let's Encrypt)
   - Set webhook URL: `https://yourdomain.com/api/telegram/webhook`

### Without Redis

This application is configured to work without Redis by using:
- Database queue driver for job processing
- Database or file cache driver for caching
- No Redis-specific features required

## Architecture

### Core Services

- **TelegramService** - Handles bot communication
- **OpenAIService** - Processes text and extracts expense data
- **OCRService** - Extracts text from receipt images
- **SpeechToTextService** - Converts voice to text
- **CategoryInferenceService** - Multi-method category detection
- **CategoryLearningService** - User-specific pattern learning

### Job Processing

All expense processing is handled asynchronously:
- `ProcessExpenseText` - Handles text expenses
- `ProcessExpenseImage` - Handles receipt photos
- `ProcessExpenseVoice` - Handles voice notes

## Troubleshooting

### Common Issues

1. **Webhook not receiving messages**
   - Verify webhook is set: `php artisan telegram:webhook:info`
   - Check ngrok is running and URL is correct
   - Ensure TELEGRAM_WEBHOOK_SECRET matches

2. **Jobs not processing**
   - Ensure queue worker is running
   - Check failed jobs: `php artisan queue:failed`
   - Verify database queue table exists

3. **OCR/Speech not working**
   - Verify Google Cloud credentials file exists
   - Check API is enabled in Google Cloud Console
   - Test with provided artisan commands

## License

This project is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

## Credits

Built with Laravel, Google Cloud APIs, OpenAI, and Telegram Bot API.