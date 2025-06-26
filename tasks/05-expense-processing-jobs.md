# Task 05: Expense Processing Jobs

## Objective
Implement asynchronous job processing for text, voice, and image expense inputs.

## Prerequisites
- Telegram integration (Task 02)
- OpenAI integration (Task 03)
- Category inference system (Task 04)
- Redis installed for queue management

## Deliverables

### 1. Configure Queue System

#### Update .env
```env
QUEUE_CONNECTION=redis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
```

### 2. Create Base Expense Processor

#### app/Jobs/BaseExpenseProcessor.php
Abstract class with common functionality:
- User validation
- Error handling and retry logic
- Telegram notification methods
- Logging setup

### 3. Create Text Processing Job

#### app/Jobs/ProcessExpenseText.php
```php
class ProcessExpenseText implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    
    public $tries = 3;
    public $timeout = 120;
    
    protected $userId;
    protected $text;
    protected $messageId;
}
```

Processing flow:
1. Extract expense data using OpenAI
2. Infer category using CategoryInferenceService
3. Create pending expense record
4. Send confirmation to user via Telegram
5. Store message context for callback handling

### 4. Create Voice Processing Job

#### app/Jobs/ProcessExpenseVoice.php
Processing flow:
1. Download voice file from Telegram
2. Convert to supported format (if needed)
3. Send to speech-to-text service
4. Process transcription as text
5. Include voice-specific confidence adjustments

### 5. Create Image Processing Job

#### app/Jobs/ProcessExpenseImage.php
Processing flow:
1. Download image from Telegram
2. Send to OCR service (Google Cloud Vision)
3. Process extracted text with OpenAI
4. Handle receipt-specific parsing
5. Extract line items if possible

### 6. Create Expense Confirmation Handler

#### app/Services/ExpenseConfirmationService.php
Handle user responses to confirmations:
- Confirm and save expense
- Edit category
- Edit description
- Cancel expense
- Update learning model on confirmation

### 7. Implement Job Failure Handling

#### Failed job handling:
- Log detailed error information
- Notify user of processing failure
- Provide manual input alternative
- Store failed job data for debugging

### 8. Create Job Monitoring

#### app/Console/Commands/MonitorExpenseJobs.php
Monitor job queue health:
- Check queue size
- Alert on high failure rate
- Clean up old failed jobs
- Generate processing statistics

## Implementation Details

### Job Retry Strategy
```php
public function retryAfter()
{
    return [30, 60, 120]; // Exponential backoff
}

public function failed(Throwable $exception)
{
    // Notify user of failure
    // Log for debugging
    // Suggest manual entry
}
```

### Temporary File Management
- Store downloaded files in storage/app/telegram/
- Clean up after processing
- Implement file size limits
- Validate file types

### Context Storage
Store processing context in Redis:
```php
Redis::setex(
    "expense_context:{$userId}:{$messageId}",
    3600, // 1 hour TTL
    json_encode($expenseData)
);
```

## Queue Configuration

### config/queue.php
```php
'redis' => [
    'driver' => 'redis',
    'connection' => 'default',
    'queue' => env('REDIS_QUEUE', 'default'),
    'retry_after' => 90,
    'block_for' => null,
    'after_commit' => false,
],
```

### Separate Queues
- `high`: Text processing (fast)
- `medium`: Voice processing
- `low`: Image processing (slow)

## Testing Strategy

### Unit Tests
- Mock external services
- Test retry logic
- Verify data extraction
- Test failure scenarios

### Integration Tests
- Test full processing flow
- Verify Telegram notifications
- Test file handling
- Confirm database updates

## Monitoring and Logging

### Log important events:
- Job start/completion times
- External API response times
- Extraction accuracy metrics
- User confirmation rates

### Metrics to track:
- Average processing time per type
- Success/failure rates
- Category inference accuracy
- User response times

## Success Criteria
- Jobs process within expected timeframes
- Proper error handling and user notification
- No memory leaks with file handling
- Accurate data extraction (>95% for text)
- Smooth user experience with clear feedback