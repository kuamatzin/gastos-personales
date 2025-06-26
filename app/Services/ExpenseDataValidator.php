<?php

namespace App\Services;

use Carbon\Carbon;

class ExpenseDataValidator
{
    /**
     * Validate expense data extracted from OpenAI
     */
    public static function validate(array $data): array
    {
        $errors = [];
        
        // Validate amount
        if (!isset($data['amount']) || !is_numeric($data['amount']) || $data['amount'] <= 0) {
            $errors[] = 'Invalid amount';
        }
        
        // Validate description
        if (empty($data['description']) || !is_string($data['description'])) {
            $errors[] = 'Invalid description';
        }
        
        // Validate date
        if (!empty($data['date'])) {
            try {
                Carbon::parse($data['date']);
            } catch (\Exception $e) {
                $errors[] = 'Invalid date format';
            }
        }
        
        // Validate currency
        if (isset($data['currency']) && !in_array($data['currency'], ['MXN', 'USD', 'EUR'])) {
            $errors[] = 'Invalid currency';
        }
        
        // Validate confidence score
        if (isset($data['confidence']) && ($data['confidence'] < 0 || $data['confidence'] > 1)) {
            $errors[] = 'Invalid confidence score';
        }
        
        return $errors;
    }

    /**
     * Sanitize expense data
     */
    public static function sanitize(array $data): array
    {
        // Ensure amount is float
        if (isset($data['amount'])) {
            $data['amount'] = floatval($data['amount']);
        }
        
        // Trim description
        if (isset($data['description'])) {
            $data['description'] = trim($data['description']);
        }
        
        // Trim merchant name
        if (isset($data['merchant_name'])) {
            $data['merchant_name'] = trim($data['merchant_name']);
        }
        
        // Ensure valid date format
        if (!empty($data['date'])) {
            try {
                $data['date'] = Carbon::parse($data['date'])->format('Y-m-d');
            } catch (\Exception $e) {
                $data['date'] = date('Y-m-d');
            }
        } else {
            $data['date'] = date('Y-m-d');
        }
        
        // Ensure confidence scores are floats between 0 and 1
        if (isset($data['confidence'])) {
            $data['confidence'] = max(0, min(1, floatval($data['confidence'])));
        }
        
        if (isset($data['category_confidence'])) {
            $data['category_confidence'] = max(0, min(1, floatval($data['category_confidence'])));
        }
        
        return $data;
    }

    /**
     * Parse amount from text with various formats
     */
    public static function parseAmount(string $text): ?float
    {
        // Remove currency symbols and spaces
        $text = str_replace(['$', '€', '£', '¥', ' ', ','], '', $text);
        
        // Try to extract numeric value
        if (preg_match('/(\d+\.?\d*)/', $text, $matches)) {
            return floatval($matches[1]);
        }
        
        return null;
    }

    /**
     * Detect currency from text
     */
    public static function detectCurrency(string $text): string
    {
        $text = strtolower($text);
        
        // Check for currency indicators
        if (str_contains($text, 'usd') || str_contains($text, 'dollar')) {
            return 'USD';
        }
        
        if (str_contains($text, 'eur') || str_contains($text, 'euro')) {
            return 'EUR';
        }
        
        // Default to MXN for Mexican context
        return 'MXN';
    }
}