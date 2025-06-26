<?php

namespace App\Strategies;

use App\Services\SpeechToTextService;
use App\Services\FileProcessingService;
use Illuminate\Support\Facades\Log;
use Exception;

class VoiceNoteStrategy implements ProcessingStrategy
{
    private SpeechToTextService $speechService;
    private FileProcessingService $fileService;
    
    private const SUPPORTED_MIME_TYPES = [
        'audio/ogg',
        'audio/mpeg',
        'audio/mp3',
        'audio/wav',
        'audio/x-wav',
        'audio/flac',
        'audio/x-flac',
        'application/ogg'
    ];
    
    public function __construct(
        SpeechToTextService $speechService,
        FileProcessingService $fileService
    ) {
        $this->speechService = $speechService;
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
     * Process voice note and extract text
     */
    public function process(string $filePath): array
    {
        try {
            Log::info('Processing voice note', ['path' => $filePath]);
            
            // Check if it's a Telegram voice note (usually OGG)
            $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            
            if ($extension === 'ogg' || $extension === 'oga') {
                // Use specialized Telegram audio processing
                $transcriptionResult = $this->speechService->processTelegramAudio($filePath);
            } else {
                // Standard audio processing
                $transcriptionResult = $this->speechService->transcribeAudio($filePath, 'es-MX');
            }
            
            if (empty($transcriptionResult['transcript'])) {
                throw new Exception('No speech detected in audio file');
            }
            
            // Extract expense information from transcript
            $expenseData = $this->extractExpenseFromTranscript(
                $transcriptionResult['transcript'],
                $transcriptionResult['language'] ?? 'es-MX'
            );
            
            return [
                'success' => true,
                'type' => 'voice',
                'transcription' => [
                    'text' => $transcriptionResult['transcript'],
                    'confidence' => $transcriptionResult['confidence'],
                    'language' => $transcriptionResult['language'] ?? 'es-MX',
                    'duration' => $transcriptionResult['duration'] ?? null,
                    'words' => $transcriptionResult['words'] ?? []
                ],
                'expense' => $expenseData,
                'metadata' => [
                    'file_size' => filesize($filePath),
                    'mime_type' => mime_content_type($filePath),
                    'duration' => $transcriptionResult['duration'] ?? null
                ]
            ];
            
        } catch (Exception $e) {
            Log::error('Voice note processing failed', [
                'path' => $filePath,
                'error' => $e->getMessage()
            ]);
            
            // Try converting audio format and retry
            if (strpos($e->getMessage(), 'format') !== false) {
                return $this->processWithConversion($filePath);
            }
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'type' => 'voice'
            ];
        }
    }
    
    /**
     * Process audio with format conversion
     */
    private function processWithConversion(string $filePath): array
    {
        try {
            Log::info('Attempting audio conversion', ['path' => $filePath]);
            
            // Convert to WAV format
            $convertedPath = $this->fileService->convertAudioFormat($filePath, 'wav');
            
            // Try transcription again
            $transcriptionResult = $this->speechService->transcribeAudio($convertedPath, 'es-MX');
            
            // Clean up converted file
            $this->fileService->cleanupTempFiles([$convertedPath]);
            
            if (empty($transcriptionResult['transcript'])) {
                throw new Exception('No speech detected after conversion');
            }
            
            // Extract expense information
            $expenseData = $this->extractExpenseFromTranscript(
                $transcriptionResult['transcript'],
                $transcriptionResult['language'] ?? 'es-MX'
            );
            
            return [
                'success' => true,
                'type' => 'voice',
                'transcription' => [
                    'text' => $transcriptionResult['transcript'],
                    'confidence' => $transcriptionResult['confidence'],
                    'language' => $transcriptionResult['language'] ?? 'es-MX',
                    'converted' => true
                ],
                'expense' => $expenseData
            ];
            
        } catch (Exception $e) {
            Log::error('Voice conversion and processing failed', [
                'path' => $filePath,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'error' => 'Failed to process audio: ' . $e->getMessage(),
                'type' => 'voice'
            ];
        }
    }
    
    /**
     * Extract expense information from transcribed text
     */
    private function extractExpenseFromTranscript(string $transcript, string $language): array
    {
        $expense = [
            'description' => $transcript,
            'confidence' => 0.7 // Base confidence for voice
        ];
        
        // Extract amounts
        $amounts = $this->findSpokenAmounts($transcript, $language);
        if (!empty($amounts)) {
            $expense['amount'] = max($amounts);
            $expense['all_amounts'] = $amounts;
        }
        
        // Extract merchant/location
        $merchant = $this->findMerchant($transcript, $language);
        if ($merchant) {
            $expense['merchant'] = $merchant;
        }
        
        // Extract payment method
        $paymentMethod = $this->findPaymentMethod($transcript, $language);
        if ($paymentMethod) {
            $expense['payment_method'] = $paymentMethod;
        }
        
        return $expense;
    }
    
    /**
     * Find amounts in spoken text
     */
    private function findSpokenAmounts(string $text, string $language): array
    {
        $amounts = [];
        $text = mb_strtolower($text);
        
        // Patterns for Spanish
        if (strpos($language, 'es') === 0) {
            $patterns = [
                '/([\d,]+(?:\.\d{2})?)\s*pesos?/',
                '/(\d+)\s*(?:con\s*)?(\d+)\s*(?:centavos?)?/', // "50 con 20 centavos"
                '/([\d,]+(?:\.\d{2})?)\s*MXN/i',
            ];
        } else {
            // English patterns
            $patterns = [
                '/([\d,]+(?:\.\d{2})?)\s*dollars?/',
                '/([\d,]+(?:\.\d{2})?)\s*pesos?/',
                '/\$([\d,]+(?:\.\d{2})?)/,
            ];
        }
        
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $text, $matches)) {
                // Handle "X con Y centavos" pattern
                if (count($matches) === 3 && isset($matches[2])) {
                    foreach ($matches[1] as $key => $whole) {
                        $cents = isset($matches[2][$key]) ? $matches[2][$key] : 0;
                        $amount = (float) str_replace(',', '', $whole) + ((float) $cents / 100);
                        if ($amount > 0 && $amount < 100000) {
                            $amounts[] = $amount;
                        }
                    }
                } else {
                    foreach ($matches[1] as $match) {
                        $amount = (float) str_replace(',', '', $match);
                        if ($amount > 0 && $amount < 100000) {
                            $amounts[] = $amount;
                        }
                    }
                }
            }
        }
        
        // Also check for written numbers in Spanish
        if (strpos($language, 'es') === 0) {
            $writtenNumbers = $this->findWrittenNumbersSpanish($text);
            $amounts = array_merge($amounts, $writtenNumbers);
        }
        
        return array_unique($amounts);
    }
    
    /**
     * Find written numbers in Spanish
     */
    private function findWrittenNumbersSpanish(string $text): array
    {
        $amounts = [];
        
        // Common written amounts
        $writtenAmounts = [
            'diez' => 10,
            'veinte' => 20,
            'treinta' => 30,
            'cuarenta' => 40,
            'cincuenta' => 50,
            'sesenta' => 60,
            'setenta' => 70,
            'ochenta' => 80,
            'noventa' => 90,
            'cien' => 100,
            'doscientos' => 200,
            'trescientos' => 300,
            'cuatrocientos' => 400,
            'quinientos' => 500,
        ];
        
        foreach ($writtenAmounts as $written => $value) {
            if (strpos($text, $written . ' pesos') !== false) {
                $amounts[] = (float) $value;
            }
        }
        
        return $amounts;
    }
    
    /**
     * Find merchant in transcript
     */
    private function findMerchant(string $text, string $language): ?string
    {
        $markers = [];
        
        if (strpos($language, 'es') === 0) {
            $markers = ['en ', 'de ', 'del ', 'tienda ', 'restaurante ', 'supermercado '];
        } else {
            $markers = ['at ', 'from ', 'store ', 'restaurant ', 'supermarket '];
        }
        
        foreach ($markers as $marker) {
            $pos = stripos($text, $marker);
            if ($pos !== false) {
                $afterMarker = substr($text, $pos + strlen($marker), 50);
                $words = explode(' ', $afterMarker);
                
                // Take first 2-3 words as merchant name
                $merchantWords = array_slice($words, 0, 3);
                $merchant = implode(' ', $merchantWords);
                
                // Clean up
                $merchant = preg_replace('/[.,;:!?]/', '', $merchant);
                $merchant = trim($merchant);
                
                if (strlen($merchant) > 2) {
                    return ucwords($merchant);
                }
            }
        }
        
        return null;
    }
    
    /**
     * Find payment method in transcript
     */
    private function findPaymentMethod(string $text, string $language): ?string
    {
        $text = mb_strtolower($text);
        
        $methods = [
            'efectivo' => 'efectivo',
            'cash' => 'efectivo',
            'tarjeta' => 'tarjeta',
            'card' => 'tarjeta',
            'credito' => 'tarjeta_credito',
            'credit' => 'tarjeta_credito',
            'debito' => 'tarjeta_debito',
            'debit' => 'tarjeta_debito',
            'transferencia' => 'transferencia',
            'transfer' => 'transferencia',
        ];
        
        foreach ($methods as $keyword => $method) {
            if (strpos($text, $keyword) !== false) {
                return $method;
            }
        }
        
        return null;
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
        return 'VoiceNoteStrategy';
    }
}