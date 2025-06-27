<?php

namespace App\Strategies;

use App\Services\FileProcessingService;
use App\Services\OCRService;
use Exception;
use Illuminate\Support\Facades\Log;

class DocumentImageStrategy implements ProcessingStrategy
{
    private OCRService $ocrService;

    private FileProcessingService $fileService;

    private const SUPPORTED_MIME_TYPES = [
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/gif',
        'image/bmp',
        'image/webp',
        'image/tiff',
    ];

    public function __construct(
        OCRService $ocrService,
        FileProcessingService $fileService
    ) {
        $this->ocrService = $ocrService;
        $this->fileService = $fileService;
    }

    /**
     * Check if this strategy can process the given file type
     */
    public function canProcess(string $mimeType): bool
    {
        return in_array(strtolower($mimeType), self::SUPPORTED_MIME_TYPES);
    }

    /**
     * Process document image and extract text
     */
    public function process(string $filePath): array
    {
        try {
            Log::info('Processing document image', ['path' => $filePath]);

            // Preprocess image
            $processedPath = $this->fileService->preprocessImage($filePath);

            // Extract text using OCR
            $ocrResult = $this->ocrService->extractTextFromImage($processedPath);

            if (empty($ocrResult['text'])) {
                throw new Exception('No text found in document image');
            }

            // Clean up processed image if different from original
            if ($processedPath !== $filePath) {
                $this->fileService->cleanupTempFiles([$processedPath]);
            }

            // Try to extract expense-related information
            $expenseData = $this->extractExpenseInfo($ocrResult['text']);

            return [
                'success' => true,
                'type' => 'document',
                'ocr' => [
                    'text' => $ocrResult['text'],
                    'confidence' => $ocrResult['confidence'],
                    'language' => $ocrResult['language'],
                    'words' => $ocrResult['words'] ?? [],
                ],
                'expense' => $expenseData,
                'metadata' => $this->fileService->extractImageMetadata($filePath),
            ];

        } catch (Exception $e) {
            Log::error('Document image processing failed', [
                'path' => $filePath,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'type' => 'document',
            ];
        }
    }

    /**
     * Get supported MIME types
     */
    public function getSupportedMimeTypes(): array
    {
        return self::SUPPORTED_MIME_TYPES;
    }

    /**
     * Get strategy name
     */
    public function getName(): string
    {
        return 'DocumentImageStrategy';
    }

    /**
     * Extract expense information from general document text
     */
    private function extractExpenseInfo(string $text): array
    {
        $expense = [];

        // Try to find amounts
        $amounts = $this->findAmounts($text);
        if (! empty($amounts)) {
            $expense['amount'] = max($amounts); // Use largest amount found
            $expense['all_amounts'] = $amounts;
        }

        // Try to find dates
        $date = $this->findDate($text);
        if ($date) {
            $expense['date'] = $date;
        }

        // Use full text as description (limited to 500 chars)
        $expense['description'] = mb_substr($text, 0, 500);

        // Set lower confidence for general documents
        $expense['confidence'] = 0.5;

        return $expense;
    }

    /**
     * Find monetary amounts in text
     */
    private function findAmounts(string $text): array
    {
        $amounts = [];

        // Pattern for amounts with currency symbols
        $patterns = [
            '/\$\s*([\d,]+\.?\d*)/',              // $123.45
            '/MXN\s*([\d,]+\.?\d*)/i',            // MXN 123.45
            '/([\d,]+\.?\d*)\s*(?:pesos|MXN)/i', // 123.45 pesos
            '/([\d,]+\.\d{2})(?!\d)/',           // 123.45 (decimal amounts)
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $text, $matches)) {
                foreach ($matches[1] as $match) {
                    $amount = (float) str_replace(',', '', $match);
                    if ($amount > 0 && $amount < 1000000) { // Reasonable amount range
                        $amounts[] = $amount;
                    }
                }
            }
        }

        return array_unique($amounts);
    }

    /**
     * Find date in text
     */
    private function findDate(string $text): ?string
    {
        $patterns = [
            // DD/MM/YYYY or DD-MM-YYYY
            '/(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})/',
            // YYYY/MM/DD or YYYY-MM-DD
            '/(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})/',
            // Month names in Spanish/English
            '/(\d{1,2})\s+(?:de\s+)?(enero|febrero|marzo|abril|mayo|junio|julio|agosto|septiembre|octubre|noviembre|diciembre|january|february|march|april|may|june|july|august|september|october|november|december)\s+(?:de\s+)?(\d{4})/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                try {
                    // Try to parse and format the date
                    $dateStr = $matches[0];
                    $date = \Carbon\Carbon::parse($dateStr);

                    return $date->format('Y-m-d');
                } catch (Exception $e) {
                    // Continue to next pattern
                }
            }
        }

        return null;
    }
}
