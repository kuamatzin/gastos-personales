# Task 09: Testing and Deployment

## Objective
Implement comprehensive testing strategy and prepare the application for production deployment.

## Prerequisites
- All core features implemented (Tasks 01-08)
- Development environment working correctly
- Hosting environment selected

## Deliverables

### 1. Unit Testing

#### Test Coverage Requirements
- Models: 100% coverage
- Services: 90% coverage
- Jobs: 85% coverage
- Commands: 80% coverage

#### Key Test Files

##### tests/Unit/Services/CategoryInferenceServiceTest.php
```php
class CategoryInferenceServiceTest extends TestCase
{
    public function test_infers_category_from_user_learning()
    public function test_falls_back_to_keyword_matching()
    public function test_uses_ai_when_confidence_low()
    public function test_calculates_confidence_correctly()
}
```

##### tests/Unit/Services/OpenAIServiceTest.php
- Mock API responses
- Test JSON parsing
- Test error handling
- Validate prompt construction

##### tests/Unit/Models/ExpenseTest.php
- Test relationships
- Test scopes
- Test accessors/mutators
- Validate business logic

### 2. Feature Testing

#### tests/Feature/TelegramWebhookTest.php
Test complete webhook flow:
- User registration
- Expense creation
- Category selection
- Confirmation handling

#### tests/Feature/ExpenseProcessingTest.php
- Text processing pipeline
- Voice processing pipeline
- Image processing pipeline
- Job retry mechanisms

#### tests/Feature/GoogleSheetsIntegrationTest.php
- Mock Google Sheets API
- Test sync operations
- Verify data formatting
- Test error recovery

### 3. Integration Testing

#### External Services Testing
Create integration tests with mocked services:
- Telegram API
- OpenAI API
- Google Cloud Vision
- Google Speech-to-Text
- Google Sheets

#### Database Testing
- Migration rollback/refresh
- Seeder integrity
- Transaction handling
- Query performance

### 4. Performance Testing

#### Load Testing Script
```php
// tests/Performance/LoadTest.php
class LoadTest extends TestCase
{
    public function test_handles_concurrent_expense_processing()
    {
        // Simulate 100 concurrent expense submissions
        // Measure response times
        // Check queue processing
    }
}
```

#### Benchmarks to establish:
- API response time < 200ms
- Job processing < 5s for text
- Database queries < 50ms
- Memory usage < 128MB per request

### 5. Security Audit

#### Security Checklist
- [ ] Telegram webhook validates signatures
- [ ] API keys stored securely
- [ ] Input validation on all endpoints
- [ ] SQL injection prevention
- [ ] XSS protection
- [ ] Rate limiting implemented
- [ ] Sensitive data encrypted
- [ ] Logs don't contain secrets

### 6. Deployment Configuration

#### Production Environment Setup

##### .env.production
```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://expensebot.yourdomain.com

# Database
DB_CONNECTION=pgsql
DB_HOST=your-db-host
DB_PORT=5432
DB_DATABASE=expensebot
DB_USERNAME=expensebot_user
DB_PASSWORD=secure_password

# Redis
REDIS_HOST=your-redis-host
REDIS_PASSWORD=redis_password

# Queue
QUEUE_CONNECTION=redis
HORIZON_ENVIRONMENT=production

# External Services
TELEGRAM_BOT_TOKEN=production_bot_token
OPENAI_API_KEY=production_api_key
GOOGLE_CLOUD_PROJECT_ID=production_project_id
```

### 7. CI/CD Pipeline

#### .github/workflows/deploy.yml
```yaml
name: Deploy to Production

on:
  push:
    branches: [main]

jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3
      - name: Setup PHP
      - name: Install dependencies
      - name: Run tests
      - name: Run security audit

  deploy:
    needs: test
    runs-on: ubuntu-latest
    steps:
      - name: Deploy to server
      - name: Run migrations
      - name: Clear caches
      - name: Restart queue workers
```

### 8. Monitoring Setup

#### Application Monitoring
- Laravel Telescope for development
- Sentry for error tracking
- New Relic/DataDog for performance

#### Custom Health Checks
```php
// app/Http/Controllers/HealthController.php
class HealthController
{
    public function check()
    {
        return [
            'app' => 'ok',
            'database' => $this->checkDatabase(),
            'redis' => $this->checkRedis(),
            'telegram' => $this->checkTelegram(),
            'openai' => $this->checkOpenAI(),
            'sheets' => $this->checkSheets(),
        ];
    }
}
```

### 9. Backup Strategy

#### Database Backups
- Daily automated PostgreSQL dumps
- 30-day retention
- Test restore process monthly

#### Google Sheets Sync
- Real-time sync serves as backup
- Weekly full export to CSV
- Store in separate cloud storage

### 10. Documentation

#### User Documentation
- Bot command reference
- Usage examples
- FAQ section
- Troubleshooting guide

#### Technical Documentation
- API documentation
- Deployment guide
- Architecture diagrams
- Runbook for common issues

## Deployment Checklist

### Pre-deployment
- [ ] All tests passing
- [ ] Security audit complete
- [ ] Performance benchmarks met
- [ ] Environment variables configured
- [ ] SSL certificate installed
- [ ] Backup strategy tested

### Deployment Steps
1. Put application in maintenance mode
2. Pull latest code
3. Install/update dependencies
4. Run migrations
5. Clear all caches
6. Restart queue workers
7. Update Telegram webhook URL
8. Run health checks
9. Monitor logs for 30 minutes

### Post-deployment
- [ ] Verify webhook receiving updates
- [ ] Test core functionality
- [ ] Check Google Sheets sync
- [ ] Monitor error rates
- [ ] Verify queue processing
- [ ] Test all bot commands

## Rollback Plan

If issues detected:
1. Revert to previous release tag
2. Restore database from backup
3. Clear all caches
4. Restart services
5. Update webhook if needed
6. Notify users if necessary

## Success Criteria
- Zero downtime deployment
- All tests passing in CI/CD
- < 0.1% error rate in production
- Response times meet SLA
- Successful daily backups
- Monitoring alerts configured