# Task 07: OCR and Voice Services Integration

## Objective
Integrate Google Cloud Vision for OCR and Google Speech-to-Text for voice processing.

## Prerequisites
- Google Cloud project with Vision and Speech APIs enabled
- Service account with appropriate permissions
- Google Cloud credentials

## Deliverables

### 1. Environment Configuration
Add to `.env`:
```env
GOOGLE_CLOUD_PROJECT_ID=your-project-id
GOOGLE_CLOUD_CREDENTIALS_PATH=storage/app/google-cloud-credentials.json
GOOGLE_CLOUD_VISION_ENABLED=true
GOOGLE_CLOUD_SPEECH_ENABLED=true
```

### 2. Install Google Cloud Libraries
```bash
composer require google/cloud-vision
composer require google/cloud-speech
```

### 3. Create OCR Service

#### app/Services/OCRService.php
Core functionality:
- `extractTextFromImage($imagePath)` - Extract text from image
- `extractReceiptData($imagePath)` - Specialized receipt processing
- `detectDocumentText($imagePath)` - For documents with structure
- `parseReceiptText($text)` - Parse extracted receipt text

Features to implement:
- Multiple language support (Spanish/English)
- Receipt-specific detection (totals, tax, items)
- Confidence scoring for extracted text
- Image preprocessing (rotation, enhancement)

### 4. Create Speech-to-Text Service

#### app/Services/SpeechToTextService.php
Core functionality:
- `transcribeAudio($audioPath, $languageCode)` - Convert audio to text
- `detectLanguage($audioPath)` - Auto-detect Spanish/English
- `transcribeWithTimestamps($audioPath)` - Get word-level timestamps
- `convertAudioFormat($inputPath, $outputPath)` - Handle Telegram audio formats

Features to implement:
- Support for OGG, MP3, WAV formats
- Automatic language detection
- Punctuation inference
- Speaker diarization (if multiple speakers)

### 5. Create File Processing Service

#### app/Services/FileProcessingService.php
Handle file operations:
- `downloadTelegramFile($fileId)` - Download from Telegram
- `preprocessImage($imagePath)` - Enhance image for OCR
- `convertAudioFormat($input, $output)` - Audio format conversion
- `cleanupTempFiles()` - Remove processed files

### 6. Implement Receipt Parser

#### app/Services/ReceiptParserService.php
Extract structured data from receipts:
```php
public function parseReceipt(string $ocrText): array
{
    return [
        'merchant' => $this->extractMerchant($ocrText),
        'total' => $this->extractTotal($ocrText),
        'tax' => $this->extractTax($ocrText),
        'date' => $this->extractDate($ocrText),
        'items' => $this->extractLineItems($ocrText),
        'payment_method' => $this->extractPaymentMethod($ocrText)
    ];
}
```

### 7. Create Audio Processing Pipeline

#### Processing flow:
1. Download audio from Telegram
2. Convert to supported format (FLAC/WAV)
3. Detect language
4. Transcribe with appropriate model
5. Post-process transcription
6. Clean up temporary files

### 8. Implement Image Processing Pipeline

#### Processing flow:
1. Download image from Telegram
2. Preprocess (resize, enhance contrast)
3. Detect text with Cloud Vision
4. Parse receipt structure
5. Extract expense data
6. Clean up temporary files

### 9. Error Handling

Handle specific scenarios:
- Blurry or low-quality images
- Background noise in audio
- Unsupported file formats
- API quotas and limits
- Network failures

### 10. Create Processing Strategies

#### app/Strategies/ImageProcessingStrategy.php
```php
interface ProcessingStrategy
{
    public function canProcess(string $mimeType): bool;
    public function process(string $filePath): array;
}

class ReceiptImageStrategy implements ProcessingStrategy
{
    // Specialized receipt processing
}

class DocumentImageStrategy implements ProcessingStrategy
{
    // General document processing
}
```

## Implementation Examples

### OCR Service Example
```php
class OCRService
{
    private $vision;
    
    public function extractTextFromImage(string $imagePath): array
    {
        $image = file_get_contents($imagePath);
        $response = $this->vision->image($image)
            ->text()
            ->detectIn();
            
        $text = $response->text();
        $confidence = $this->calculateConfidence($response);
        
        return [
            'text' => $text,
            'confidence' => $confidence,
            'language' => $response->locale(),
            'raw_response' => $response
        ];
    }
}
```

### Speech Service Example
```php
class SpeechToTextService
{
    private $speech;
    
    public function transcribeAudio(string $audioPath, string $languageCode = 'es-MX'): array
    {
        $audio = file_get_contents($audioPath);
        
        $config = [
            'encoding' => 'OGG_OPUS',
            'sampleRateHertz' => 48000,
            'languageCode' => $languageCode,
            'enableAutomaticPunctuation' => true,
            'model' => 'latest_long'
        ];
        
        $response = $this->speech->recognize($audio, $config);
        
        return [
            'transcript' => $response->transcript(),
            'confidence' => $response->confidence(),
            'words' => $response->words()
        ];
    }
}
```

## Performance Optimization

1. **File Size Limits**
   - Images: Max 20MB
   - Audio: Max 10MB (1 minute)
   - Implement client-side validation

2. **Preprocessing**
   - Resize images before OCR
   - Compress audio if needed
   - Cache processed results

3. **Parallel Processing**
   - Process multiple images concurrently
   - Use job batching for bulk operations

## Testing Strategy

### Unit Tests
- Mock Google Cloud APIs
- Test file format conversions
- Verify text extraction logic

### Integration Tests
- Test with sample receipts
- Test various audio formats
- Verify language detection

### Test Data
Create test dataset with:
- Various receipt formats
- Different audio qualities
- Multiple languages
- Edge cases (blurry, rotated)

## Success Criteria
- OCR accuracy > 90% on clear receipts
- Speech recognition accuracy > 95% in quiet environments
- Support for Spanish and English
- Processing time < 5 seconds for images
- Processing time < 10 seconds for 1-minute audio