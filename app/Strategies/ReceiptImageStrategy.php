<?php

namespace App\Strategies;

use App\Services\FileProcessingService;
use App\Services\OCRService;
use App\Services\ReceiptParserService;
use Exception;
use Illuminate\Support\Facades\Log;

class ReceiptImageStrategy implements ProcessingStrategy
{
    private OCRService $ocrService;

    private ReceiptParserService $parserService;

    private FileProcessingService $fileService;

    private const SUPPORTED_MIME_TYPES = [
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/gif',
        'image/bmp',
        'image/webp',
    ];

    public function __construct(
        OCRService $ocrService,
        ReceiptParserService $parserService,
        FileProcessingService $fileService
    ) {
        $this->ocrService = $ocrService;
        $this->parserService = $parserService;
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
     * Process receipt image and extract expense data
     */
    public function process(string $filePath): array
    {
        try {
            Log::info('Processing receipt image', ['path' => $filePath]);

            // Preprocess image for better OCR results
            $processedPath = $this->fileService->preprocessImage($filePath);

            // Extract text using OCR
            $ocrResult = $this->ocrService->extractReceiptData($processedPath);

            if (empty($ocrResult['text'])) {
                throw new Exception('No text found in receipt image');
            }

            // Parse receipt data
            $receiptData = $this->parserService->parseReceipt($ocrResult['text']);

            // Clean up processed image if different from original
            if ($processedPath !== $filePath) {
                $this->fileService->cleanupTempFiles([$processedPath]);
            }

            // Combine results
            return [
                'success' => true,
                'type' => 'receipt',
                'ocr' => [
                    'text' => $ocrResult['text'],
                    'confidence' => $ocrResult['confidence'],
                    'blocks' => $ocrResult['blocks'] ?? [],
                ],
                'parsed' => $receiptData,
                'expense' => $this->mapToExpenseData($receiptData),
                'metadata' => $this->fileService->extractImageMetadata($filePath),
            ];

        } catch (Exception $e) {
            Log::error('Receipt image processing failed', [
                'path' => $filePath,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'type' => 'receipt',
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
        return 'ReceiptImageStrategy';
    }

    /**
     * Map parsed receipt data to expense format
     */
    private function mapToExpenseData(array $receiptData): array
    {
        $expense = [
            'amount' => $receiptData['total'] ?? 0,
            'description' => $this->buildDescription($receiptData),
            'merchant' => $receiptData['merchant'],
            'date' => $receiptData['date'],
            'payment_method' => $receiptData['payment_method'],
            'tax_amount' => $receiptData['tax'],
            'reference_number' => $receiptData['reference_number'],
            'confidence' => $receiptData['confidence'] ?? 0.8,
        ];

        // Add items if available
        if (! empty($receiptData['items'])) {
            $expense['items'] = $receiptData['items'];
        }

        return array_filter($expense, function ($value) {
            return $value !== null;
        });
    }

    /**
     * Build expense description from receipt data
     */
    private function buildDescription(array $receiptData): string
    {
        $parts = [];

        if ($receiptData['merchant']) {
            $parts[] = $receiptData['merchant'];
        }

        if (! empty($receiptData['items'])) {
            $itemCount = count($receiptData['items']);
            $parts[] = "({$itemCount} items)";
        }

        if ($receiptData['payment_method']) {
            $parts[] = '- '.$receiptData['payment_method'];
        }

        return implode(' ', $parts) ?: 'Receipt expense';
    }
}
