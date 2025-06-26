<?php

namespace App\Services;

use App\Models\CategoryLearning;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CategoryLearningService
{
    /**
     * Find best matching category based on user's learning history
     */
    public function findBestMatch(User $user, string $description): ?array
    {
        $keywords = $this->extractKeywords($description);
        
        if (empty($keywords)) {
            return null;
        }

        $matches = [];

        // Search for each keyword in user's learning history
        foreach ($keywords as $keyword) {
            $learnings = CategoryLearning::where('user_id', $user->id)
                ->where(function ($query) use ($keyword) {
                    $query->where('keyword', $keyword)
                        ->orWhere('keyword', 'LIKE', "%{$keyword}%");
                })
                ->orderByDesc('confidence_weight')
                ->orderByDesc('usage_count')
                ->limit(5)
                ->get();

            foreach ($learnings as $learning) {
                $categoryId = $learning->category_id;
                
                if (!isset($matches[$categoryId])) {
                    $matches[$categoryId] = [
                        'category_id' => $categoryId,
                        'total_weight' => 0,
                        'keyword_count' => 0,
                        'keywords' => []
                    ];
                }
                
                $matches[$categoryId]['total_weight'] += $learning->confidence_weight;
                $matches[$categoryId]['keyword_count']++;
                $matches[$categoryId]['keywords'][] = $keyword;
            }
        }

        if (empty($matches)) {
            return null;
        }

        // Calculate confidence for each match
        $bestMatch = null;
        $highestConfidence = 0;

        foreach ($matches as $categoryId => $match) {
            // Calculate confidence based on:
            // - Total weight of matching keywords
            // - Number of matching keywords vs total keywords
            // - Recency factor (handled by confidence_weight)
            
            $keywordCoverage = $match['keyword_count'] / count($keywords);
            $averageWeight = $match['total_weight'] / $match['keyword_count'];
            
            // Confidence formula
            $confidence = ($keywordCoverage * 0.6) + (min($averageWeight, 2) / 2 * 0.4);
            
            if ($confidence > $highestConfidence) {
                $highestConfidence = $confidence;
                $bestMatch = [
                    'category_id' => $categoryId,
                    'confidence' => min($confidence, 1.0),
                    'matching_keywords' => $match['keywords']
                ];
            }
        }

        return $bestMatch;
    }

    /**
     * Learn from user's category choice
     */
    public function learnFromUserChoice(User $user, string $description, int $categoryId): void
    {
        $keywords = $this->extractKeywords($description);

        foreach ($keywords as $keyword) {
            $learning = CategoryLearning::firstOrNew([
                'user_id' => $user->id,
                'keyword' => $keyword,
                'category_id' => $categoryId
            ]);

            if ($learning->exists) {
                // Increment existing learning
                $learning->incrementUsage();
            } else {
                // Create new learning
                $learning->confidence_weight = 1.0;
                $learning->usage_count = 1;
                $learning->last_used_at = now();
                $learning->save();
            }
        }

        // Also learn from merchant names if identifiable
        $merchantName = $this->extractMerchantName($description);
        if ($merchantName) {
            $this->learnMerchant($user, $merchantName, $categoryId);
        }
    }

    /**
     * Learn merchant-category association
     */
    private function learnMerchant(User $user, string $merchantName, int $categoryId): void
    {
        $merchantKeyword = 'merchant:' . strtolower($merchantName);
        
        $learning = CategoryLearning::firstOrNew([
            'user_id' => $user->id,
            'keyword' => $merchantKeyword,
            'category_id' => $categoryId
        ]);

        if ($learning->exists) {
            // Merchant associations get higher weight increase
            $learning->confidence_weight = min(2.0, $learning->confidence_weight + 0.2);
            $learning->usage_count++;
            $learning->last_used_at = now();
            $learning->save();
        } else {
            $learning->confidence_weight = 1.5; // Higher initial weight for merchants
            $learning->usage_count = 1;
            $learning->last_used_at = now();
            $learning->save();
        }
    }

    /**
     * Extract keywords from description
     */
    private function extractKeywords(string $description): array
    {
        // Convert to lowercase and remove special characters
        $text = strtolower($description);
        $text = preg_replace('/[^\w\s\-]/u', ' ', $text);
        
        // Split into words
        $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        
        // Remove stop words
        $stopWords = $this->getStopWords();
        $keywords = array_filter($words, function($word) use ($stopWords) {
            return strlen($word) >= 3 && !in_array($word, $stopWords);
        });
        
        // Also extract bigrams (two-word combinations) for better context
        $bigrams = [];
        for ($i = 0; $i < count($words) - 1; $i++) {
            if (!in_array($words[$i], $stopWords) && !in_array($words[$i + 1], $stopWords)) {
                $bigram = $words[$i] . ' ' . $words[$i + 1];
                if (strlen($bigram) >= 5) {
                    $bigrams[] = $bigram;
                }
            }
        }
        
        return array_unique(array_merge(array_values($keywords), $bigrams));
    }

    /**
     * Extract merchant name from description
     */
    private function extractMerchantName(string $description): ?string
    {
        // Common patterns for merchant names
        $patterns = [
            '/(?:en|at|@)\s+([A-Z][a-zA-Z\s&\']+)/i',
            '/([A-Z][a-zA-Z]+(?:\s+[A-Z][a-zA-Z]+)*)/u',
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $description, $matches)) {
                $merchant = trim($matches[1]);
                // Validate it looks like a merchant name
                if (strlen($merchant) >= 3 && !is_numeric($merchant)) {
                    return $merchant;
                }
            }
        }
        
        return null;
    }

    /**
     * Get stop words for Spanish and English
     */
    private function getStopWords(): array
    {
        return [
            // Spanish
            'el', 'la', 'de', 'que', 'y', 'a', 'en', 'un', 'ser', 'se',
            'no', 'haber', 'por', 'con', 'su', 'para', 'como', 'estar',
            'tener', 'le', 'lo', 'todo', 'pero', 'más', 'hacer', 'o',
            'poder', 'decir', 'este', 'ir', 'otro', 'ese', 'si', 'me',
            'ya', 'ver', 'porque', 'dar', 'cuando', 'muy', 'sin', 'vez',
            'mucho', 'saber', 'qué', 'sobre', 'mi', 'alguno', 'mismo',
            'también', 'hasta', 'año', 'dos', 'querer', 'entre', 'así',
            'primero', 'desde', 'grande', 'eso', 'ni', 'nos', 'llegar',
            'pasar', 'tiempo', 'ella', 'sí', 'día', 'uno', 'bien', 'poco',
            'deber', 'entonces', 'poner', 'cosa', 'tanto', 'hombre', 'parecer',
            'nuestro', 'tan', 'donde', 'ahora', 'parte', 'después', 'vida',
            'quedar', 'siempre', 'creer', 'hablar', 'llevar', 'dejar', 'nada',
            'cada', 'seguir', 'menos', 'nuevo', 'encontrar',
            
            // English
            'the', 'be', 'to', 'of', 'and', 'a', 'in', 'that', 'have',
            'i', 'it', 'for', 'not', 'on', 'with', 'he', 'as', 'you',
            'do', 'at', 'this', 'but', 'his', 'by', 'from', 'they',
            'we', 'say', 'her', 'she', 'or', 'an', 'will', 'my', 'one',
            'all', 'would', 'there', 'their', 'what', 'so', 'up', 'out',
            'if', 'about', 'who', 'get', 'which', 'go', 'me', 'when',
            'make', 'can', 'like', 'time', 'no', 'just', 'him', 'know',
            'take', 'people', 'into', 'year', 'your', 'good', 'some',
            'could', 'them', 'see', 'other', 'than', 'then', 'now',
            'look', 'only', 'come', 'its', 'over', 'think', 'also',
            'back', 'after', 'use', 'two', 'how', 'our', 'work',
            
            // Common expense words to exclude
            'pesos', 'peso', 'dollar', 'dollars', 'usd', 'mxn', 'eur',
            'gaste', 'spent', 'pague', 'paid', 'compre', 'bought'
        ];
    }

    /**
     * Decay old learning entries (maintenance method)
     */
    public function decayOldLearnings(int $daysOld = 90): void
    {
        // Reduce confidence weight for old entries
        CategoryLearning::where('last_used_at', '<', now()->subDays($daysOld))
            ->where('confidence_weight', '>', 0.5)
            ->update([
                'confidence_weight' => DB::raw('confidence_weight * 0.9')
            ]);
    }

    /**
     * Get user's learning statistics
     */
    public function getUserLearningStats(User $user): array
    {
        $stats = CategoryLearning::where('user_id', $user->id)
            ->selectRaw('COUNT(DISTINCT keyword) as unique_keywords')
            ->selectRaw('COUNT(DISTINCT category_id) as categories_learned')
            ->selectRaw('SUM(usage_count) as total_usage')
            ->selectRaw('AVG(confidence_weight) as avg_confidence')
            ->first();
        
        return [
            'unique_keywords' => $stats->unique_keywords ?? 0,
            'categories_learned' => $stats->categories_learned ?? 0,
            'total_usage' => $stats->total_usage ?? 0,
            'average_confidence' => round($stats->avg_confidence ?? 0, 2),
        ];
    }
}