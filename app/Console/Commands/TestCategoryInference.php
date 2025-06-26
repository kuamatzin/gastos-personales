<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\CategoryInferenceService;
use App\Services\CategoryLearningService;
use Illuminate\Console\Command;

class TestCategoryInference extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'category:test {description} {amount?} {--user=} {--learn}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test category inference system';

    /**
     * Execute the console command.
     */
    public function handle(
        CategoryInferenceService $inferenceService,
        CategoryLearningService $learningService
    ) {
        $description = $this->argument('description');
        $amount = $this->argument('amount');
        
        // Get or create test user
        $userId = $this->option('user') ?? 'test_user';
        $user = User::firstOrCreate(
            ['telegram_id' => $userId],
            [
                'name' => 'Test User',
                'email' => $userId . '@test.local',
                'password' => bcrypt('password')
            ]
        );
        
        $this->info("Testing category inference for: \"{$description}\"");
        if ($amount) {
            $this->info("Amount: $" . number_format($amount, 2) . " MXN");
        }
        $this->info("User: {$user->name} (ID: {$user->id})\n");
        
        // Test inference
        $result = $inferenceService->inferCategory($user, $description, $amount);
        
        // Display results
        $category = \App\Models\Category::find($result['category_id']);
        
        if ($category) {
            $categoryDisplay = ($category->icon ?? '📋') . ' ' . $category->name;
            if ($category->parent) {
                $categoryDisplay = $category->parent->icon . ' ' . $category->parent->name . ' > ' . $category->name;
            }
            
            $this->info("🎯 Inferred Category: {$categoryDisplay}");
            $this->info("📊 Confidence: " . round($result['confidence'] * 100) . "%");
            $this->info("🔧 Method: " . $result['method']);
            
            // Show category keywords
            if ($category->keywords) {
                $this->info("🏷️ Keywords: " . implode(', ', array_slice($category->keywords, 0, 5)));
            }
            
            // Test learning if requested
            if ($this->option('learn')) {
                $this->info("\n📚 Learning from this categorization...");
                $learningService->learnFromUserChoice($user, $description, $category->id);
                $this->info("✅ Learning completed!");
                
                // Show what was learned
                $keywords = $this->extractKeywords($description);
                $this->info("💡 Learned keywords: " . implode(', ', $keywords));
            }
            
            // Show user's learning stats
            $stats = $learningService->getUserLearningStats($user);
            if ($stats['total_usage'] > 0) {
                $this->info("\n📈 User Learning Stats:");
                $this->table(
                    ['Metric', 'Value'],
                    [
                        ['Unique Keywords', $stats['unique_keywords']],
                        ['Categories Learned', $stats['categories_learned']],
                        ['Total Usage', $stats['total_usage']],
                        ['Avg Confidence', $stats['average_confidence']],
                    ]
                );
            }
            
            // Build and show suggestions
            $suggestions = $inferenceService->buildCategorySuggestions(
                $result['confidence'],
                $result['category_id'],
                $user,
                $description
            );
            
            if (count($suggestions) > 1) {
                $this->info("\n💡 Category Suggestions:");
                foreach ($suggestions as $suggestion) {
                    $marker = $suggestion['is_primary'] ? '→' : ' ';
                    $confidence = $suggestion['confidence'] > 0 
                        ? ' (' . round($suggestion['confidence'] * 100) . '%)' 
                        : '';
                    $this->info("{$marker} {$suggestion['icon']} {$suggestion['name']}{$confidence}");
                }
            }
            
        } else {
            $this->error("❌ Could not infer category");
        }
    }
    
    private function extractKeywords(string $description): array
    {
        $text = strtolower($description);
        $text = preg_replace('/[^\w\s\-]/u', ' ', $text);
        $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        
        $stopWords = ['de', 'en', 'el', 'la', 'los', 'las', 'un', 'una', 'the', 'a', 'an', 'at', 'in', 'on'];
        
        return array_values(array_filter($words, function($word) use ($stopWords) {
            return strlen($word) >= 3 && !in_array($word, $stopWords);
        }));
    }
}
