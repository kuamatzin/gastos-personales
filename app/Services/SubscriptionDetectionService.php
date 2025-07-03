<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class SubscriptionDetectionService
{
    /**
     * Keywords that indicate a subscription in Spanish and English
     */
    private array $subscriptionKeywords = [
        // Spanish
        'suscripción', 'suscripcion', 'mensual', 'mensualidad', 'membresía', 'membresia',
        'plan', 'cuota', 'renovación', 'renovacion', 'anualidad', 'trimestral',
        'quincenal', 'semanal', 'recurrente', 'pago recurrente', 'cobro recurrente',
        
        // English
        'subscription', 'monthly', 'membership', 'plan', 'renewal', 'annual',
        'quarterly', 'biweekly', 'weekly', 'recurring', 'recurring payment',
        
        // Services (Spanish & English)
        'netflix', 'spotify', 'amazon prime', 'disney', 'hbo', 'apple', 'youtube',
        'gym', 'gimnasio', 'crossfit', 'internet', 'telefono', 'celular', 'mobile',
        'cloud', 'storage', 'software', 'app', 'premium', 'pro', 'plus',
    ];

    /**
     * Patterns that indicate subscription payments
     */
    private array $subscriptionPatterns = [
        // Spanish patterns
        '/pago\s+(mensual|anual|trimestral|quincenal|semanal)\s+de/i',
        '/cuota\s+(mensual|anual|trimestral|quincenal|semanal)/i',
        '/mensualidad\s+de/i',
        '/renovación\s+de/i',
        '/suscripción\s+(a|de)/i',
        '/membresía\s+(de|del)/i',
        '/plan\s+(mensual|anual|familiar|individual)/i',
        
        // English patterns
        '/monthly\s+payment\s+for/i',
        '/(monthly|annual|quarterly)\s+subscription/i',
        '/membership\s+fee/i',
        '/renewal\s+for/i',
        '/subscription\s+to/i',
        '/recurring\s+payment/i',
    ];

    /**
     * Detect if an expense text indicates a subscription
     */
    public function detectSubscription(string $text): array
    {
        $lowerText = mb_strtolower($text);
        $confidence = 0.0;
        $reasons = [];
        
        // Check for keyword matches
        $keywordMatches = 0;
        foreach ($this->subscriptionKeywords as $keyword) {
            if (str_contains($lowerText, mb_strtolower($keyword))) {
                $keywordMatches++;
                $reasons[] = "Contains subscription keyword: $keyword";
            }
        }
        
        if ($keywordMatches > 0) {
            $confidence += min(0.5, $keywordMatches * 0.25);
        }
        
        // Check for pattern matches
        $patternMatches = 0;
        foreach ($this->subscriptionPatterns as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                $patternMatches++;
                $reasons[] = "Matches subscription pattern: {$matches[0]}";
            }
        }
        
        if ($patternMatches > 0) {
            $confidence += min(0.5, $patternMatches * 0.35);
        }
        
        // Check for specific service names (higher confidence)
        $knownServices = [
            'netflix' => 0.9,
            'spotify' => 0.9,
            'amazon prime' => 0.9,
            'disney' => 0.85,
            'hbo' => 0.85,
            'youtube premium' => 0.9,
            'apple music' => 0.9,
            'gym' => 0.7,
            'gimnasio' => 0.7,
        ];
        
        foreach ($knownServices as $service => $serviceConfidence) {
            if (str_contains($lowerText, $service)) {
                $confidence = max($confidence, $serviceConfidence);
                $reasons[] = "Known subscription service: $service";
                break;
            }
        }
        
        $isSubscription = $confidence >= 0.5;
        
        Log::info('Subscription detection result', [
            'text' => $text,
            'is_subscription' => $isSubscription,
            'confidence' => $confidence,
            'reasons' => $reasons,
        ]);
        
        return [
            'is_subscription' => $isSubscription,
            'confidence' => $confidence,
            'reasons' => $reasons,
            'suggested_periodicity' => $this->detectPeriodicity($text),
        ];
    }

    /**
     * Try to detect the periodicity from the text
     */
    private function detectPeriodicity(string $text): ?string
    {
        $lowerText = mb_strtolower($text);
        
        $periodicityMap = [
            'monthly' => ['mensual', 'mensualidad', 'mes', 'monthly', 'month'],
            'yearly' => ['anual', 'año', 'annual', 'yearly', 'year'],
            'quarterly' => ['trimestral', 'trimestre', 'quarterly', 'quarter'],
            'biweekly' => ['quincenal', 'quincena', 'biweekly', 'bi-weekly'],
            'weekly' => ['semanal', 'semana', 'weekly', 'week'],
            'daily' => ['diario', 'diaria', 'día', 'daily', 'day'],
        ];
        
        foreach ($periodicityMap as $periodicity => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($lowerText, $keyword)) {
                    return $periodicity;
                }
            }
        }
        
        // Default to monthly for most subscriptions
        return 'monthly';
    }

    /**
     * Check if expense amount and merchant match existing subscriptions
     */
    public function checkExistingSubscription(int $userId, float $amount, ?string $merchantName = null): ?array
    {
        $query = \App\Models\Subscription::where('user_id', $userId)
            ->where('status', 'active')
            ->where('amount', $amount);
        
        if ($merchantName) {
            $query->where('merchant_name', 'LIKE', '%' . $merchantName . '%');
        }
        
        $subscription = $query->first();
        
        if ($subscription) {
            return [
                'subscription_id' => $subscription->id,
                'name' => $subscription->name,
                'periodicity' => $subscription->periodicity,
                'next_charge_date' => $subscription->next_charge_date->format('Y-m-d'),
            ];
        }
        
        return null;
    }
}