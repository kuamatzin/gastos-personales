# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**ExpenseBot** - A Laravel 12 application for personal expense tracking with Telegram bot integration. The system uses AI-powered categorization through OpenAI to automatically categorize expenses from text, voice, and image inputs.

## Key Commands

### Development
```bash
# Install dependencies
composer install
npm install

# Run development environment (concurrent server, queue, logs, and vite)
composer dev

# Run Laravel server only
php artisan serve

# Run queue worker
php artisan queue:listen --tries=1

# Run database migrations
php artisan migrate

# Seed categories
php artisan db:seed --class=CategorySeeder
```

### Testing & Code Quality
```bash
# Run tests
composer test
# or
php artisan test

# Run code style fixer
./vendor/bin/pint

# Build frontend assets
npm run build
```

### Docker Development (Laravel Sail)
```bash
# Start Docker environment
./vendor/bin/sail up

# Run artisan commands in Docker
./vendor/bin/sail artisan migrate
```

## Architecture Overview

### Core Services Architecture
- **TelegramService**: Handles bot interactions and message processing
- **OpenAIService**: Manages text extraction and category inference
- **CategoryInferenceService**: Multi-method category detection (user patterns, keywords, AI)
- **CategoryLearningService**: Implements user-specific learning for improved categorization
- **GoogleSheetsService**: Syncs expenses to Google Sheets for analysis

### Database Structure
- **expenses**: Core expense records with category assignments and confidence scores
- **categories**: Hierarchical category structure with keywords for matching
- **category_learning**: User-specific patterns for adaptive categorization
- **users**: User records linked to Telegram IDs

### Job Queue System
All expense processing is handled asynchronously through Laravel jobs:
- ProcessExpenseText
- ProcessExpenseImage
- ProcessExpenseVoice

### AI Integration Strategy
1. **User Learning**: Check CategoryLearning table for user-specific patterns
2. **Keyword Matching**: Match against category keywords with confidence scoring
3. **OpenAI Fallback**: Use GPT-3.5-turbo for complex categorization

## Key Implementation Notes

### Telegram Bot Integration
- Webhook endpoint: `/api/telegram/webhook`
- Commands: `/start`, `/help`, `/expenses_today`, `/expenses_month`
- Supports text, voice notes, and image uploads

### Category System
- Hierarchical structure (parent/child categories)
- Pre-seeded with common Mexican expense categories
- Keywords in both English and Spanish
- Confidence scoring for automatic vs. manual categorization

### Google Sheets Integration
- Primary storage remains PostgreSQL
- Sheets used for backup and user-friendly analysis
- Automatic sync after each expense save

## Environment Configuration

Required `.env` variables:
```
TELEGRAM_BOT_TOKEN=
OPENAI_API_KEY=
GOOGLE_SHEETS_CREDENTIALS=
GOOGLE_SHEETS_ID=
```

## Development Workflow

1. Feature branches should follow: `feature/description`
2. Queue workers must be running for expense processing
3. Use SQLite for local development (default)
4. PostgreSQL recommended for production

## Current Implementation Status

The project is currently a fresh Laravel installation. The PRD outlines the complete system, but implementation has not begun. Key components to build:

1. Database migrations for all models
2. Telegram webhook controller
3. AI service integrations
4. Job processors for async handling
5. Google Sheets sync service
6. Category seeder with Mexican context