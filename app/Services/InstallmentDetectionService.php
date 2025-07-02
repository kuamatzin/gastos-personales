<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class InstallmentDetectionService
{
    /**
     * Spanish number words mapping
     */
    private array $spanishNumbers = [
        'un' => 1, 'una' => 1, 'uno' => 1,
        'dos' => 2, 'tres' => 3, 'cuatro' => 4, 'cinco' => 5,
        'seis' => 6, 'siete' => 7, 'ocho' => 8, 'nueve' => 9,
        'diez' => 10, 'once' => 11, 'doce' => 12,
        'trece' => 13, 'catorce' => 14, 'quince' => 15,
        'dieciseis' => 16, 'diecisiete' => 17, 'dieciocho' => 18,
        'diecinueve' => 19, 'veinte' => 20, 'veintiuno' => 21,
        'veintidos' => 22, 'veintitres' => 23, 'veinticuatro' => 24,
        'treinta' => 30, 'cuarenta' => 40, 'cincuenta' => 50,
        'sesenta' => 60,
    ];

    /**
     * Detect installment patterns in text
     */
    public function detectInstallments(string $text): ?array
    {
        $text = strtolower(trim($text));
        
        Log::info('Detecting installments in text', ['text' => $text]);

        // Try different pattern detection methods
        $result = $this->detectWithoutInterestPattern($text) ??
                 $this->detectWithInterestPattern($text) ??
                 $this->detectGeneralInstallmentPattern($text);

        if ($result) {
            Log::info('Installment detected', $result);
        }

        return $result;
    }

    /**
     * Detect "sin intereses" (without interest) patterns
     * Examples: 
     * - "12000 pesos en lavadora a 12 meses sin intereses"
     * - "5000 pesos a seis meses sin intereses"
     */
    private function detectWithoutInterestPattern(string $text): ?array
    {
        // Pattern for "X pesos ... a Y meses sin intereses"
        $patterns = [
            '/(\d+(?:\.\d+)?)\s*pesos?.*?a\s+(\d+|' . implode('|', array_keys($this->spanishNumbers)) . ')\s+meses?\s+sin\s+intereses?/i',
            '/(\d+(?:\.\d+)?)\s*pesos?.*?(\d+|' . implode('|', array_keys($this->spanishNumbers)) . ')\s+meses?\s+sin\s+intereses?/i',
            '/(\d+(?:\.\d+)?)\s*pesos?.*?sin\s+intereses?\s+a?\s*(\d+|' . implode('|', array_keys($this->spanishNumbers)) . ')\s+meses?/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                $totalAmount = (float) $matches[1];
                $months = $this->parseNumberWord($matches[2]);
                
                if ($totalAmount > 0 && $months > 0 && $months <= 60) {
                    $monthlyAmount = $totalAmount / $months;
                    
                    return [
                        'is_installment' => true,
                        'total_amount' => $totalAmount,
                        'monthly_amount' => round($monthlyAmount, 2),
                        'months' => $months,
                        'has_interest' => false,
                        'pattern_matched' => 'without_interest',
                        'confidence' => 0.95,
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Detect "con intereses" (with interest) patterns
     * Examples:
     * - "13000 pesos en horno a 6 meses con intereses, 3000 pesos mensuales"
     * - "10000 pesos a doce meses con intereses de 1200 mensuales"
     */
    private function detectWithInterestPattern(string $text): ?array
    {
        // Pattern for "X pesos ... a Y meses con intereses ... Z pesos mensuales"
        $patterns = [
            '/(\d+(?:\.\d+)?)\s*pesos?.*?a\s+(\d+|' . implode('|', array_keys($this->spanishNumbers)) . ')\s+meses?\s+con\s+intereses?.*?(\d+(?:\.\d+)?)\s*pesos?\s+mensuales?/i',
            '/(\d+(?:\.\d+)?)\s*pesos?.*?(\d+|' . implode('|', array_keys($this->spanishNumbers)) . ')\s+meses?\s+con\s+intereses?.*?(\d+(?:\.\d+)?)\s*mensuales?/i',
            '/(\d+(?:\.\d+)?)\s*pesos?.*?con\s+intereses?.*?(\d+|' . implode('|', array_keys($this->spanishNumbers)) . ')\s+meses?.*?(\d+(?:\.\d+)?)\s*pesos?\s+mensuales?/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                $totalAmount = (float) $matches[1];
                $months = $this->parseNumberWord($matches[2]);
                $monthlyAmount = (float) $matches[3];
                
                if ($totalAmount > 0 && $months > 0 && $months <= 60 && $monthlyAmount > 0) {
                    return [
                        'is_installment' => true,
                        'total_amount' => $totalAmount,
                        'monthly_amount' => $monthlyAmount,
                        'months' => $months,
                        'has_interest' => true,
                        'pattern_matched' => 'with_interest',
                        'confidence' => 0.95,
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Detect general installment patterns (less specific)
     * Examples:
     * - "8000 pesos a 10 meses"
     * - "compra a meses sin intereses"
     */
    private function detectGeneralInstallmentPattern(string $text): ?array
    {
        // Basic pattern for "X pesos a Y meses"
        $patterns = [
            '/(\d+(?:\.\d+)?)\s*pesos?.*?a\s+(\d+|' . implode('|', array_keys($this->spanishNumbers)) . ')\s+meses?/i',
            '/(\d+(?:\.\d+)?)\s*pesos?.*?(\d+|' . implode('|', array_keys($this->spanishNumbers)) . ')\s+meses?/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                $totalAmount = (float) $matches[1];
                $months = $this->parseNumberWord($matches[2]);
                
                if ($totalAmount > 0 && $months > 1 && $months <= 60) {
                    // Check if it mentions interest
                    $hasInterest = $this->detectInterestMention($text);
                    $monthlyAmount = $totalAmount / $months;
                    
                    return [
                        'is_installment' => true,
                        'total_amount' => $totalAmount,
                        'monthly_amount' => round($monthlyAmount, 2),
                        'months' => $months,
                        'has_interest' => $hasInterest,
                        'pattern_matched' => 'general',
                        'confidence' => 0.75, // Lower confidence for general pattern
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Parse number word to integer
     */
    private function parseNumberWord(string $word): int
    {
        $word = strtolower(trim($word));
        
        // If it's already a number, return it
        if (is_numeric($word)) {
            return (int) $word;
        }
        
        // Check Spanish number words
        return $this->spanishNumbers[$word] ?? 0;
    }

    /**
     * Detect if text mentions interest
     */
    private function detectInterestMention(string $text): bool
    {
        $interestKeywords = [
            'con intereses', 'con interés', 'intereses', 'interés',
            'financiamiento', 'crédito', 'préstamo'
        ];
        
        $noInterestKeywords = [
            'sin intereses', 'sin interés', '0% interés', 'cero interés',
            'meses sin intereses', 'msi'
        ];
        
        // Check for explicit "no interest" mentions first
        foreach ($noInterestKeywords as $keyword) {
            if (str_contains($text, $keyword)) {
                return false;
            }
        }
        
        // Check for interest mentions
        foreach ($interestKeywords as $keyword) {
            if (str_contains($text, $keyword)) {
                return true;
            }
        }
        
        return false; // Default to no interest if not mentioned
    }

    /**
     * Extract item description from installment text
     */
    public function extractItemDescription(string $text): string
    {
        $text = trim($text);
        
        // Remove common prefixes
        $prefixes = ['gasto por', 'gasto de', 'compra de', 'compré', 'compra'];
        foreach ($prefixes as $prefix) {
            $pattern = '/^' . preg_quote($prefix, '/') . '\s+/i';
            $text = preg_replace($pattern, '', $text);
        }
        
        // Extract the item name (usually between amount and installment info)
        $patterns = [
            '/\d+(?:\.\d+)?\s*pesos?\s+(?:en|de|para)\s+([^a]+?)\s+a\s+\d+\s+meses?/i',
            '/\d+(?:\.\d+)?\s*pesos?\s+([^a]+?)\s+a\s+\d+\s+meses?/i',
            '/([^0-9]+?)\s+a\s+\d+\s+meses?/i',
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                $description = trim($matches[1]);
                // Clean up common words
                $description = preg_replace('/\b(en|de|para|por)\b/i', '', $description);
                $description = trim($description);
                
                if (strlen($description) > 2) {
                    return $description;
                }
            }
        }
        
        // Fallback: try to extract any meaningful description
        $words = explode(' ', $text);
        $meaningfulWords = [];
        
        foreach ($words as $word) {
            $word = trim($word);
            // Skip numbers, currency, and common installment words
            if (!is_numeric($word) && 
                !in_array(strtolower($word), ['pesos', 'peso', 'meses', 'mes', 'sin', 'con', 'intereses', 'interes', 'a']) &&
                strlen($word) > 2) {
                $meaningfulWords[] = $word;
            }
        }
        
        return implode(' ', array_slice($meaningfulWords, 0, 3)) ?: 'Compra a meses';
    }

    /**
     * Validate installment data
     */
    public function validateInstallmentData(array $data): bool
    {
        return isset($data['total_amount'], $data['monthly_amount'], $data['months']) &&
               $data['total_amount'] > 0 &&
               $data['monthly_amount'] > 0 &&
               $data['months'] > 1 &&
               $data['months'] <= 60 &&
               $data['monthly_amount'] <= $data['total_amount'];
    }
}