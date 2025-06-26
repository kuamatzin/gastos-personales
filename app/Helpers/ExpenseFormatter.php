<?php

namespace App\Helpers;

use Carbon\Carbon;

class ExpenseFormatter
{
    /**
     * Format amount with currency
     */
    public static function formatAmount(float $amount, string $currency = 'MXN'): string
    {
        return '$' . number_format($amount, 2) . ' ' . $currency;
    }
    
    /**
     * Format percentage with sign
     */
    public static function formatPercentage(float $value, int $decimals = 1): string
    {
        $formatted = number_format(abs($value), $decimals) . '%';
        
        if ($value > 0) {
            return '+' . $formatted;
        } elseif ($value < 0) {
            return '-' . $formatted;
        }
        
        return $formatted;
    }
    
    /**
     * Format percentage change with arrow
     */
    public static function formatPercentageChange(float $value, bool $includeArrow = true): string
    {
        $percentage = self::formatPercentage($value);
        
        if (!$includeArrow) {
            return $percentage;
        }
        
        if ($value > 0) {
            return '↑ ' . $percentage;
        } elseif ($value < 0) {
            return '↓ ' . $percentage;
        }
        
        return '→ ' . $percentage;
    }
    
    /**
     * Get emoji for category
     */
    public static function getCategoryEmoji(string $category): string
    {
        $emojis = [
            // Spanish categories
            'comida' => '🍽️',
            'alimentos' => '🍽️',
            'restaurante' => '🍽️',
            'transporte' => '🚗',
            'uber' => '🚗',
            'taxi' => '🚗',
            'gasolina' => '⛽',
            'compras' => '🛍️',
            'shopping' => '🛍️',
            'ropa' => '👕',
            'entretenimiento' => '🎬',
            'cine' => '🎬',
            'salud' => '💊',
            'medicina' => '💊',
            'doctor' => '👨‍⚕️',
            'servicios' => '📱',
            'telefono' => '📱',
            'internet' => '🌐',
            'luz' => '💡',
            'agua' => '💧',
            'educacion' => '📚',
            'educación' => '📚',
            'escuela' => '🎓',
            'hogar' => '🏠',
            'casa' => '🏠',
            'renta' => '🏠',
            'personal' => '👤',
            'viajes' => '✈️',
            'viaje' => '✈️',
            'hotel' => '🏨',
            'otros' => '📦',
            'miscelaneos' => '📦',
            
            // English categories
            'food' => '🍽️',
            'dining' => '🍽️',
            'groceries' => '🛒',
            'transport' => '🚗',
            'transportation' => '🚗',
            'gas' => '⛽',
            'health' => '💊',
            'healthcare' => '💊',
            'bills' => '📱',
            'utilities' => '🏠',
            'education' => '📚',
            'entertainment' => '🎬',
            'travel' => '✈️',
            'personal' => '👤',
            'other' => '📦',
            'miscellaneous' => '📦',
        ];
        
        $key = strtolower($category);
        
        // Direct match
        if (isset($emojis[$key])) {
            return $emojis[$key];
        }
        
        // Partial match
        foreach ($emojis as $keyword => $emoji) {
            if (str_contains($key, $keyword) || str_contains($keyword, $key)) {
                return $emoji;
            }
        }
        
        return '📌'; // Default emoji
    }
    
    /**
     * Format date in user-friendly way
     */
    public static function formatDate(Carbon $date, string $format = 'relative'): string
    {
        switch ($format) {
            case 'relative':
                if ($date->isToday()) {
                    return 'Today';
                } elseif ($date->isYesterday()) {
                    return 'Yesterday';
                } elseif ($date->diffInDays() < 7) {
                    return $date->format('l'); // Day name
                } else {
                    return $date->format('d/m');
                }
                
            case 'short':
                return $date->format('d/m');
                
            case 'medium':
                return $date->format('d M');
                
            case 'long':
                return $date->format('d F Y');
                
            case 'full':
                return $date->format('l, d F Y');
                
            default:
                return $date->format($format);
        }
    }
    
    /**
     * Format time period
     */
    public static function formatPeriod(Carbon $start, Carbon $end): string
    {
        if ($start->isSameDay($end)) {
            return $start->format('d F Y');
        }
        
        if ($start->isSameMonth($end)) {
            return $start->format('d') . '-' . $end->format('d F Y');
        }
        
        if ($start->isSameYear($end)) {
            return $start->format('d M') . ' - ' . $end->format('d M Y');
        }
        
        return $start->format('d M Y') . ' - ' . $end->format('d M Y');
    }
    
    /**
     * Format expense description
     */
    public static function formatDescription(string $description, int $maxLength = 50): string
    {
        if (strlen($description) <= $maxLength) {
            return $description;
        }
        
        return mb_substr($description, 0, $maxLength - 3) . '...';
    }
    
    /**
     * Get spending level indicator
     */
    public static function getSpendingLevel(float $amount, float $average): string
    {
        $ratio = $average > 0 ? $amount / $average : 0;
        
        if ($ratio < 0.5) {
            return '🟢 Low';
        } elseif ($ratio < 0.8) {
            return '🟡 Normal';
        } elseif ($ratio < 1.2) {
            return '🟠 Above Average';
        } else {
            return '🔴 High';
        }
    }
    
    /**
     * Format file size
     */
    public static function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $factor = floor((strlen($bytes) - 1) / 3);
        
        return sprintf("%.1f %s", $bytes / pow(1024, $factor), $units[$factor]);
    }
    
    /**
     * Get trend indicator
     */
    public static function getTrendIndicator(float $current, float $previous): string
    {
        if ($previous == 0) {
            return $current > 0 ? '📈' : '➖';
        }
        
        $change = (($current - $previous) / $previous) * 100;
        
        if ($change > 10) {
            return '📈'; // Significant increase
        } elseif ($change > 0) {
            return '↗️'; // Slight increase
        } elseif ($change < -10) {
            return '📉'; // Significant decrease
        } elseif ($change < 0) {
            return '↘️'; // Slight decrease
        } else {
            return '➖'; // No change
        }
    }
    
    /**
     * Format confidence level
     */
    public static function formatConfidence(float $confidence): string
    {
        if ($confidence >= 0.9) {
            return '🟢 High';
        } elseif ($confidence >= 0.7) {
            return '🟡 Medium';
        } else {
            return '🔴 Low';
        }
    }
}