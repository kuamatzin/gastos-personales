<?php

namespace App\Services;

use Exception;
use Google\Cloud\Vision\V1\Feature\Type;
use Google\Cloud\Vision\V1\Image;
use Google\Cloud\Vision\V1\ImageAnnotatorClient;
use Google\Cloud\Vision\V1\ImageContext;
use Illuminate\Support\Facades\Log;

class OCRService
{
    private ?ImageAnnotatorClient $vision = null;

    public function __construct()
    {
        if (config('services.google_cloud.vision_enabled')) {
            $this->initializeClient();
        }
    }

    /**
     * Initialize Google Cloud Vision client
     */
    private function initializeClient(): void
    {
        try {
            $credentials = config('services.google_cloud.credentials_path');

            if (! file_exists($credentials)) {
                throw new Exception('Google Cloud credentials file not found');
            }

            $this->vision = new ImageAnnotatorClient([
                'credentials' => $credentials,
            ]);
        } catch (Exception $e) {
            Log::error('Failed to initialize Cloud Vision client', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Extract text from an image using OCR
     */
    public function extractTextFromImage(string $imagePath): array
    {
        if (! $this->vision) {
            throw new Exception('OCR service is not initialized');
        }

        try {
            // Read image content
            $imageContent = file_get_contents($imagePath);
            if (! $imageContent) {
                throw new Exception('Failed to read image file');
            }

            // Create image object
            $image = new Image;
            $image->setContent($imageContent);

            // Set language hints for better Spanish/English detection
            $imageContext = new ImageContext;
            $imageContext->setLanguageHints(['es', 'en']);

            // Perform text detection
            $response = $this->vision->textDetection($image, [
                'imageContext' => $imageContext,
            ]);

            $annotations = $response->getTextAnnotations();

            if ($annotations->count() === 0) {
                return [
                    'text' => '',
                    'confidence' => 0,
                    'language' => null,
                    'words' => [],
                ];
            }

            // Get full text from first annotation
            $fullText = $annotations[0]->getDescription();

            // Calculate average confidence from all text blocks
            $confidenceSum = 0;
            $confidenceCount = 0;
            $words = [];

            foreach ($annotations as $index => $annotation) {
                if ($index === 0) {
                    continue;
                } // Skip the full text annotation

                $words[] = [
                    'text' => $annotation->getDescription(),
                    'confidence' => $annotation->getConfidence() ?? 0.9,
                    'bounds' => $this->extractBounds($annotation),
                ];

                if ($annotation->getConfidence()) {
                    $confidenceSum += $annotation->getConfidence();
                    $confidenceCount++;
                }
            }

            $averageConfidence = $confidenceCount > 0
                ? $confidenceSum / $confidenceCount
                : 0.9; // Default confidence if not provided

            // Detect primary language
            $language = $this->detectLanguage($fullText);

            return [
                'text' => $fullText,
                'confidence' => $averageConfidence,
                'language' => $language,
                'words' => $words,
            ];

        } catch (Exception $e) {
            Log::error('OCR text extraction failed', [
                'image_path' => $imagePath,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            if (isset($response)) {
                $response->clear();
            }
        }
    }

    /**
     * Extract receipt-specific data from an image
     */
    public function extractReceiptData(string $imagePath): array
    {
        if (! $this->vision) {
            throw new Exception('OCR service is not initialized');
        }

        try {
            // Read image content
            $imageContent = file_get_contents($imagePath);
            if (! $imageContent) {
                throw new Exception('Failed to read image file');
            }

            // Create image object
            $image = new Image;
            $image->setContent($imageContent);

            // Use document text detection for better structured text extraction
            $response = $this->vision->documentTextDetection($image);

            $annotation = $response->getFullTextAnnotation();

            if (! $annotation) {
                return [
                    'text' => '',
                    'blocks' => [],
                    'confidence' => 0,
                ];
            }

            $fullText = $annotation->getText();
            $blocks = [];

            // Extract structured blocks from pages
            foreach ($annotation->getPages() as $page) {
                foreach ($page->getBlocks() as $block) {
                    $blockText = '';
                    $blockConfidence = $block->getConfidence() ?? 0.9;

                    foreach ($block->getParagraphs() as $paragraph) {
                        foreach ($paragraph->getWords() as $word) {
                            $wordText = '';
                            foreach ($word->getSymbols() as $symbol) {
                                $wordText .= $symbol->getText();
                            }
                            $blockText .= $wordText.' ';
                        }
                        $blockText .= "\n";
                    }

                    $blocks[] = [
                        'text' => trim($blockText),
                        'confidence' => $blockConfidence,
                        'type' => $this->inferBlockType($blockText),
                    ];
                }
            }

            return [
                'text' => $fullText,
                'blocks' => $blocks,
                'confidence' => $this->calculateAverageConfidence($blocks),
            ];

        } catch (Exception $e) {
            Log::error('Receipt OCR extraction failed', [
                'image_path' => $imagePath,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            if (isset($response)) {
                $response->clear();
            }
        }
    }

    /**
     * Detect document text with structure preservation
     */
    public function detectDocumentText(string $imagePath): array
    {
        return $this->extractReceiptData($imagePath);
    }

    /**
     * Parse extracted receipt text into structured data
     */
    public function parseReceiptText(string $text): array
    {
        $lines = explode("\n", $text);
        $result = [
            'merchant' => null,
            'total' => null,
            'subtotal' => null,
            'tax' => null,
            'date' => null,
            'items' => [],
            'payment_method' => null,
            'raw_text' => $text,
        ];

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            // Try to extract total
            if (! $result['total'] && $this->isTotal($line)) {
                $result['total'] = $this->extractAmount($line);
            }

            // Try to extract subtotal
            if (! $result['subtotal'] && $this->isSubtotal($line)) {
                $result['subtotal'] = $this->extractAmount($line);
            }

            // Try to extract tax
            if (! $result['tax'] && $this->isTax($line)) {
                $result['tax'] = $this->extractAmount($line);
            }

            // Try to extract date
            if (! $result['date'] && $date = $this->extractDate($line)) {
                $result['date'] = $date;
            }

            // Try to extract payment method
            if (! $result['payment_method'] && $method = $this->extractPaymentMethod($line)) {
                $result['payment_method'] = $method;
            }

            // Try to extract merchant (usually in first few lines)
            if (! $result['merchant'] && $this->isPotentialMerchant($line)) {
                $result['merchant'] = $line;
            }
        }

        // If no merchant found, use first non-empty line
        if (! $result['merchant'] && count($lines) > 0) {
            foreach ($lines as $line) {
                if (! empty(trim($line))) {
                    $result['merchant'] = trim($line);
                    break;
                }
            }
        }

        return $result;
    }

    /**
     * Extract bounds from annotation
     */
    private function extractBounds($annotation): array
    {
        $bounds = [];
        $boundingPoly = $annotation->getBoundingPoly();

        if ($boundingPoly) {
            foreach ($boundingPoly->getVertices() as $vertex) {
                $bounds[] = [
                    'x' => $vertex->getX(),
                    'y' => $vertex->getY(),
                ];
            }
        }

        return $bounds;
    }

    /**
     * Detect language from text
     */
    private function detectLanguage(string $text): string
    {
        // Simple language detection based on common words
        $spanishWords = ['de', 'la', 'el', 'en', 'y', 'a', 'que', 'es', 'del', 'con', 'por', 'para'];
        $englishWords = ['the', 'of', 'and', 'to', 'in', 'is', 'it', 'for', 'with', 'on', 'at'];

        $words = str_word_count(strtolower($text), 1);
        $spanishCount = 0;
        $englishCount = 0;

        foreach ($words as $word) {
            if (in_array($word, $spanishWords)) {
                $spanishCount++;
            }
            if (in_array($word, $englishWords)) {
                $englishCount++;
            }
        }

        return $spanishCount > $englishCount ? 'es' : 'en';
    }

    /**
     * Infer block type from text content
     */
    private function inferBlockType(string $text): string
    {
        $text = strtolower($text);

        if (preg_match('/total|sum|amount due/i', $text)) {
            return 'total';
        }

        if (preg_match('/\$?\s*\d+[.,]\d{2}/', $text)) {
            return 'price';
        }

        if (preg_match('/\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4}/', $text)) {
            return 'date';
        }

        if (preg_match('/visa|mastercard|amex|credit|debit|cash|efectivo/i', $text)) {
            return 'payment';
        }

        return 'text';
    }

    /**
     * Calculate average confidence from blocks
     */
    private function calculateAverageConfidence(array $blocks): float
    {
        if (empty($blocks)) {
            return 0;
        }

        $sum = array_sum(array_column($blocks, 'confidence'));

        return $sum / count($blocks);
    }

    /**
     * Check if line contains total
     */
    private function isTotal(string $line): bool
    {
        return preg_match('/total\s*:?\s*\$?\s*[\d.,]+|total\s+amount|amount\s+due|total\s+a\s+pagar/i', $line);
    }

    /**
     * Check if line contains subtotal
     */
    private function isSubtotal(string $line): bool
    {
        return preg_match('/subtotal\s*:?\s*\$?\s*[\d.,]+|sub\s*total/i', $line);
    }

    /**
     * Check if line contains tax
     */
    private function isTax(string $line): bool
    {
        return preg_match('/tax\s*:?\s*\$?\s*[\d.,]+|iva\s*:?\s*\$?\s*[\d.,]+|impuesto/i', $line);
    }

    /**
     * Extract amount from line
     */
    private function extractAmount(string $line): ?float
    {
        if (preg_match('/\$?\s*([\d,]+\.?\d*)/', $line, $matches)) {
            $amount = str_replace(',', '', $matches[1]);

            return (float) $amount;
        }

        return null;
    }

    /**
     * Extract date from line
     */
    private function extractDate(string $line): ?string
    {
        // Try various date formats
        $patterns = [
            '/(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})/',     // DD/MM/YYYY or MM/DD/YYYY
            '/(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{2})/',      // DD/MM/YY or MM/DD/YY
            '/(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})/',      // YYYY/MM/DD
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $line, $matches)) {
                return $matches[0];
            }
        }

        return null;
    }

    /**
     * Extract payment method from line
     */
    private function extractPaymentMethod(string $line): ?string
    {
        $line = strtolower($line);

        if (strpos($line, 'visa') !== false) {
            return 'visa';
        }
        if (strpos($line, 'mastercard') !== false) {
            return 'mastercard';
        }
        if (strpos($line, 'amex') !== false || strpos($line, 'american express') !== false) {
            return 'amex';
        }
        if (strpos($line, 'debit') !== false || strpos($line, 'débito') !== false) {
            return 'debit';
        }
        if (strpos($line, 'credit') !== false || strpos($line, 'crédito') !== false) {
            return 'credit';
        }
        if (strpos($line, 'cash') !== false || strpos($line, 'efectivo') !== false) {
            return 'cash';
        }

        return null;
    }

    /**
     * Check if line could be merchant name
     */
    private function isPotentialMerchant(string $line): bool
    {
        // Merchant names are usually in uppercase or title case
        // and don't contain prices or dates
        if (preg_match('/\$\s*[\d.,]+/', $line)) {
            return false;
        }
        if (preg_match('/\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4}/', $line)) {
            return false;
        }
        if (strlen($line) < 3 || strlen($line) > 50) {
            return false;
        }

        // Check if mostly uppercase or title case
        $uppercaseCount = preg_match_all('/[A-Z]/', $line);
        $letterCount = preg_match_all('/[A-Za-z]/', $line);

        return $letterCount > 0 && ($uppercaseCount / $letterCount) > 0.5;
    }

    /**
     * Clean up resources
     */
    public function __destruct()
    {
        if ($this->vision) {
            $this->vision->close();
        }
    }
}
