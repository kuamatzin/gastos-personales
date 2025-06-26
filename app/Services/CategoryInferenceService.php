<?php

namespace App\Services;

use App\Models\Category;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CategoryInferenceService
{
    private OpenAIService $openAIService;
    private CategoryLearningService $categoryLearningService;

    public function __construct(
        OpenAIService $openAIService,
        CategoryLearningService $categoryLearningService
    ) {
        $this->openAIService = $openAIService;
        $this->categoryLearningService = $categoryLearningService;
    }

    /**
     * Infer category using multiple methods with priority
     */
    public function inferCategory(User $user, string $description, ?float $amount = null): array
    {
        // 1. Try user's historical patterns first (highest priority)
        $userPattern = $this->categoryLearningService->findBestMatch($user, $description);
        
        if ($userPattern && $userPattern['confidence'] >= 0.85) {
            return [
                'category_id' => $userPattern['category_id'],
                'confidence' => $userPattern['confidence'],
                'method' => 'user_learning'
            ];
        }

        // 2. Use keyword matching with predefined categories
        $keywordMatch = $this->matchByKeywords($description, $amount);
        
        if ($keywordMatch && $keywordMatch['confidence'] >= 0.75) {
            return $keywordMatch;
        }

        // 3. Use OpenAI for complex inference
        $aiInference = $this->openAIService->inferCategory($description, $amount);
        
        return [
            'category_id' => $aiInference['category_id'],
            'confidence' => $aiInference['confidence'],
            'method' => 'ai_inference'
        ];
    }

    /**
     * Match description against category keywords
     */
    private function matchByKeywords(string $description, ?float $amount = null): ?array
    {
        $description = strtolower($description);
        $bestMatch = null;
        $highestScore = 0;

        $categories = $this->getCachedCategories();

        foreach ($categories as $category) {
            $score = 0;
            $keywords = $category->keywords ?? [];

            // Calculate keyword match score
            foreach ($keywords as $keyword) {
                $keyword = strtolower($keyword);
                if (str_contains($description, $keyword)) {
                    // Give higher score for exact word matches
                    if (preg_match('/\b' . preg_quote($keyword, '/') . '\b/', $description)) {
                        $score += 0.3;
                    } else {
                        $score += 0.15;
                    }
                    
                    // Bonus for longer keywords (more specific)
                    $score += strlen($keyword) / 100;
                }
            }

            // Check if merchant name matches
            if (!empty($category->keywords)) {
                foreach ($category->keywords as $merchant) {
                    if (str_contains($description, strtolower($merchant))) {
                        $score += 0.4;
                        break;
                    }
                }
            }

            // Bonus for amount patterns
            if ($amount && $this->matchesAmountPattern($category, $amount)) {
                $score += 0.2;
            }

            // Consider parent category keywords too
            if ($category->parent) {
                $parentKeywords = $category->parent->keywords ?? [];
                foreach ($parentKeywords as $keyword) {
                    if (str_contains($description, strtolower($keyword))) {
                        $score += 0.1;
                    }
                }
            }

            if ($score > $highestScore) {
                $highestScore = $score;
                $bestMatch = [
                    'category_id' => $category->id,
                    'confidence' => min($score, 0.95),
                    'method' => 'keyword_matching'
                ];
            }
        }

        return $bestMatch;
    }

    /**
     * Check if amount matches typical patterns for a category
     */
    private function matchesAmountPattern(Category $category, float $amount): bool
    {
        $amountPatterns = $this->getAmountPatterns();
        
        $slug = $category->slug;
        $pattern = $amountPatterns[$slug] ?? null;

        if (!$pattern) {
            // Check parent category pattern
            if ($category->parent) {
                $pattern = $amountPatterns[$category->parent->slug] ?? null;
            }
        }

        if (!$pattern) {
            return false;
        }

        return $amount >= $pattern['min'] && $amount <= $pattern['max'];
    }

    /**
     * Get amount patterns for categories (Mexican peso amounts)
     */
    private function getAmountPatterns(): array
    {
        return [
            // Food & Dining
            'coffee_shops' => ['min' => 30, 'max' => 200],
            'fast_food' => ['min' => 50, 'max' => 300],
            'restaurants' => ['min' => 150, 'max' => 2000],
            'groceries' => ['min' => 100, 'max' => 3000],
            'delivery' => ['min' => 80, 'max' => 500],
            'alcohol' => ['min' => 50, 'max' => 2000],
            
            // Transportation
            'public_transport' => ['min' => 5, 'max' => 50],
            'ride_sharing' => ['min' => 40, 'max' => 500],
            'fuel' => ['min' => 200, 'max' => 1500],
            'parking' => ['min' => 10, 'max' => 200],
            'tolls' => ['min' => 20, 'max' => 500],
            
            // Shopping
            'clothing' => ['min' => 100, 'max' => 5000],
            'electronics' => ['min' => 200, 'max' => 50000],
            'personal_care' => ['min' => 50, 'max' => 1000],
            
            // Entertainment
            'movies' => ['min' => 50, 'max' => 300],
            'streaming_services' => ['min' => 99, 'max' => 299],
            
            // Bills
            'rent_mortgage' => ['min' => 3000, 'max' => 50000],
            'electricity' => ['min' => 200, 'max' => 5000],
            'internet' => ['min' => 300, 'max' => 1500],
            'phone' => ['min' => 200, 'max' => 1000],
        ];
    }

    /**
     * Build category suggestions for user interface
     */
    public function buildCategorySuggestions(float $confidence, int $categoryId, User $user, string $description): array
    {
        $suggestions = [];
        
        // Primary suggestion
        $primaryCategory = Category::find($categoryId);
        if ($primaryCategory) {
            $suggestions[] = [
                'category_id' => $categoryId,
                'name' => $primaryCategory->name,
                'icon' => $primaryCategory->icon ?? '📋',
                'confidence' => $confidence,
                'is_primary' => true
            ];
        }

        // If confidence is not high, add alternatives
        if ($confidence < 0.9) {
            // Get user's frequently used categories
            $frequentCategories = $this->getUserFrequentCategories($user, 3);
            
            foreach ($frequentCategories as $category) {
                if ($category->id !== $categoryId) {
                    $suggestions[] = [
                        'category_id' => $category->id,
                        'name' => $category->name,
                        'icon' => $category->icon ?? '📋',
                        'confidence' => 0,
                        'is_primary' => false
                    ];
                }
            }
            
            // Add "Other" category as last option
            $otherCategory = Category::where('slug', 'miscellaneous')->first();
            if ($otherCategory && $otherCategory->id !== $categoryId) {
                $suggestions[] = [
                    'category_id' => $otherCategory->id,
                    'name' => $otherCategory->name,
                    'icon' => $otherCategory->icon ?? '📋',
                    'confidence' => 0,
                    'is_primary' => false
                ];
            }
        }

        return $suggestions;
    }

    /**
     * Get user's frequently used categories
     */
    private function getUserFrequentCategories(User $user, int $limit = 5)
    {
        return Cache::remember("user_frequent_categories_{$user->id}", 1800, function () use ($user, $limit) {
            return Category::select('categories.*', DB::raw('COUNT(expenses.id) as usage_count'))
                ->join('expenses', 'categories.id', '=', 'expenses.category_id')
                ->where('expenses.user_id', $user->id)
                ->where('expenses.status', 'confirmed')
                ->where('expenses.created_at', '>=', now()->subDays(30))
                ->groupBy('categories.id')
                ->orderBy('usage_count', 'desc')
                ->limit($limit)
                ->get();
        });
    }

    /**
     * Get cached categories with keywords
     */
    private function getCachedCategories()
    {
        return Cache::remember('categories_with_keywords', 3600, function () {
            return Category::with('parent')
                ->where('is_active', true)
                ->get();
        });
    }

    /**
     * Calculate confidence score based on multiple factors
     */
    public function calculateConfidenceScore(array $factors): float
    {
        $weights = [
            'keyword_match' => 0.3,
            'amount_pattern' => 0.2,
            'user_history' => 0.3,
            'ai_confidence' => 0.2,
        ];

        $totalScore = 0;
        $totalWeight = 0;

        foreach ($factors as $factor => $score) {
            if (isset($weights[$factor])) {
                $totalScore += $score * $weights[$factor];
                $totalWeight += $weights[$factor];
            }
        }

        return $totalWeight > 0 ? $totalScore / $totalWeight : 0;
    }
}