# Task 04: Category Inference System

## Objective
Implement the multi-method category inference system that combines user learning, keyword matching, and AI inference.

## Prerequisites
- Database setup completed (Task 01)
- OpenAI integration completed (Task 03)
- Categories seeded in database

## Deliverables

### 1. Create Category Inference Service

#### app/Services/CategoryInferenceService.php
Main service that orchestrates category inference using multiple methods:

##### inferCategory(User $user, string $description, ?float $amount): array
Returns:
```php
[
    'category_id' => 123,
    'confidence' => 0.92,
    'method' => 'user_learning|keyword_matching|ai_inference'
]
```

##### Inference Priority:
1. User's historical patterns (confidence >= 0.85)
2. Keyword matching (confidence >= 0.75)
3. OpenAI inference (fallback)

### 2. Create Category Learning Service

#### app/Services/CategoryLearningService.php

##### findBestMatch(User $user, string $description): ?array
- Extract keywords from description
- Search user's learning history
- Return best matching category with confidence

##### learnFromUserChoice(User $user, string $description, int $categoryId): void
- Extract keywords from description
- Update or create learning records
- Increment confidence weights and usage counts

##### extractKeywords(string $description): array
- Remove stop words (Spanish and English)
- Extract meaningful keywords (3+ characters)
- Handle merchant names

### 3. Implement Keyword Matching Logic

#### Enhanced matching features:
- Partial keyword matching with scoring
- Merchant name recognition
- Amount pattern matching per category
- Multi-language support (Spanish/English)

#### Amount Pattern Examples:
```php
$amountPatterns = [
    'coffee_shops' => ['min' => 30, 'max' => 150],
    'public_transport' => ['min' => 5, 'max' => 50],
    'groceries' => ['min' => 100, 'max' => 2000],
    'restaurants' => ['min' => 150, 'max' => 1500],
];
```

### 4. Create Category Suggestion Builder

Build suggestion interface for Telegram that shows:
- Primary suggestion with confidence
- Alternative categories if confidence < 90%
- Option to view all categories
- Remember user's choice for learning

### 5. Implement Confidence Scoring

#### Scoring factors:
- Keyword match strength (0-0.5)
- Amount pattern match (0-0.2)
- Historical usage frequency (0-0.3)

#### Confidence thresholds:
- High (>90%): Auto-assign
- Medium (70-90%): Suggest with alternatives
- Low (<70%): Show full category list

### 6. Create Category Cache

Implement caching for:
- Active categories list
- Category keywords
- Common merchant-category mappings

Use Laravel's cache with 1-hour TTL.

## Implementation Example

```php
class CategoryInferenceService
{
    public function inferCategory(User $user, string $description, ?float $amount = null): array
    {
        // 1. Try user's historical patterns
        $userPattern = $this->categoryLearningService->findBestMatch($user, $description);
        
        if ($userPattern && $userPattern['confidence'] >= 0.85) {
            return [
                'category_id' => $userPattern['category_id'],
                'confidence' => $userPattern['confidence'],
                'method' => 'user_learning'
            ];
        }
        
        // 2. Try keyword matching
        $keywordMatch = $this->matchByKeywords($description, $amount);
        
        if ($keywordMatch && $keywordMatch['confidence'] >= 0.75) {
            return $keywordMatch;
        }
        
        // 3. Use OpenAI
        return $this->openAIInference($description, $amount);
    }
}
```

## Testing Strategy

### Unit Tests
- Test keyword extraction with various inputs
- Test confidence scoring calculations
- Mock user learning data
- Test amount pattern matching

### Integration Tests
- Test full inference flow
- Verify learning updates
- Test caching behavior

## Performance Optimization

1. **Database Queries**
   - Eager load category relationships
   - Index keyword columns
   - Optimize learning queries

2. **Caching Strategy**
   - Cache category data
   - Cache user's frequent categories
   - Invalidate on category updates

## Success Criteria
- Category inference accuracy > 90% for common expenses
- User learning improves accuracy over time
- Response time < 500ms for cached data
- Proper fallback to AI when needed
- Confidence scoring is accurate and meaningful