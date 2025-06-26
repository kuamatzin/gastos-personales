# Task 03: OpenAI Integration

## Objective
Integrate OpenAI GPT-3.5-turbo for expense data extraction and category inference.

## Prerequisites
- OpenAI API key
- Basic Laravel services structure

## Deliverables

### 1. Environment Configuration
Add to `.env`:
```env
OPENAI_API_KEY=your_openai_api_key_here
OPENAI_MODEL=gpt-3.5-turbo
OPENAI_MAX_TOKENS=250
OPENAI_TEMPERATURE=0.1
```

### 2. Create OpenAI Service

#### app/Services/OpenAIService.php
Implement core OpenAI functionality:

##### extractExpenseData(string $text): array
Extract structured data from user input:
- Amount (numeric)
- Description
- Initial category suggestion
- Category confidence
- Merchant name (if identifiable)
- Date (default to today)
- Overall confidence score

##### inferCategory(string $description, ?float $amount): array
Advanced category inference:
- Consider Mexican context
- Use amount patterns
- Return category slug with confidence
- Provide reasoning

##### processImageText(string $ocrText): array
Process OCR output from receipts:
- Clean and structure messy OCR text
- Extract total amount
- Identify merchant
- Extract date
- List items if possible

##### processVoiceTranscription(string $transcription): array
Process speech-to-text output:
- Handle informal speech patterns
- Extract expense information
- Support Spanish and English

### 3. Create Configuration

#### config/services.php
```php
'openai' => [
    'key' => env('OPENAI_API_KEY'),
    'model' => env('OPENAI_MODEL', 'gpt-3.5-turbo'),
    'max_tokens' => env('OPENAI_MAX_TOKENS', 250),
    'temperature' => env('OPENAI_TEMPERATURE', 0.1),
],
```

### 4. Implement Prompt Templates

Create well-structured prompts that:
- Return only valid JSON
- Include all available categories
- Consider Mexican peso (MXN) as default currency
- Handle bilingual input (Spanish/English)

### 5. Error Handling

Implement robust error handling for:
- API rate limits
- Invalid responses
- Network timeouts
- Malformed JSON responses

### 6. Response Validation

Create validation logic to ensure:
- Amount is numeric and positive
- Category exists in database
- Date is valid format
- Confidence scores are between 0 and 1

## Implementation Example

```php
public function extractExpenseData(string $text): array
{
    $categories = $this->getCategoryList();
    
    $prompt = "Extract expense information from the following text and return ONLY valid JSON:
    
    Text: {$text}
    
    Available categories: " . implode(', ', $categories) . "
    
    Expected format:
    {
        \"amount\": 123.45,
        \"description\": \"expense description\",
        \"category\": \"category_slug\",
        \"category_confidence\": 0.95,
        \"merchant_name\": \"merchant name if identifiable\",
        \"date\": \"YYYY-MM-DD\",
        \"confidence\": 0.95
    }";
    
    // Make API call and handle response
}
```

## Testing Strategy

### Unit Tests
- Mock OpenAI API responses
- Test various input formats
- Validate JSON parsing
- Test error scenarios

### Integration Tests
- Test with real API (limited)
- Verify response structure
- Test timeout handling

## Performance Considerations

1. **Caching**
   - Cache category lists
   - Consider caching common merchant-category mappings

2. **Batch Processing**
   - Group similar requests when possible
   - Implement queue throttling

3. **Token Optimization**
   - Minimize prompt size
   - Use concise response formats

## Success Criteria
- Successfully extracts expense data from various text formats
- Handles Spanish and English input
- Returns valid JSON consistently
- Proper error handling and logging
- Category inference accuracy > 80%
- Response time < 3 seconds