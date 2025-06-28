<?php

namespace App\Services;

use App\Models\Category;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAIService
{
    private string $apiKey;

    private string $model;

    private int $maxTokens;

    private float $temperature;

    private string $apiUrl = 'https://api.openai.com/v1/chat/completions';

    public function __construct()
    {
        $this->apiKey = config('services.openai.key');
        $this->model = config('services.openai.model');
        $this->maxTokens = config('services.openai.max_tokens');
        $this->temperature = config('services.openai.temperature');
    }

    /**
     * Extract expense data from user input text
     */
    public function extractExpenseData(string $text): array
    {
        $categories = $this->getCategoryList();

        $prompt = "Extract expense information from the following text and return ONLY valid JSON:

Text: {$text}

Available categories: ".implode(', ', $categories)."

Expected format:
{
    \"amount\": 123.45,
    \"description\": \"expense description\",
    \"category\": \"category_slug\",
    \"category_confidence\": 0.95,
    \"merchant_name\": \"merchant name if identifiable\",
    \"date\": \"YYYY-MM-DD\",
    \"confidence\": 0.95
}

Rules:
- Parse dates carefully:
  - 'ayer' or 'yesterday' = ".date('Y-m-d', strtotime('-1 day'))."
  - 'antier' or 'anteayer' = ".date('Y-m-d', strtotime('-2 days'))."
  - 'hoy' or 'today' = ".date('Y-m-d')."
  - 'esta semana' = current week
  - 'la semana pasada' = last week
  - If no date mentioned at all, use today: ".date('Y-m-d').'
- Choose the most appropriate category from the list
- Extract merchant name if clearly identifiable
- Amount must be numeric without currency symbols
- Default currency is MXN
- category_confidence should reflect how certain you are about the category (0.0-1.0)
- Description should be clear and concise
- Consider Mexican context (common stores, services, etc.)';

        try {
            $response = $this->makeApiCall($prompt);
            $data = $this->parseJsonResponse($response);

            // Validate and normalize the response
            return $this->normalizeExpenseData($data);
        } catch (\Exception $e) {
            Log::error('OpenAI extractExpenseData failed', [
                'error' => $e->getMessage(),
                'text' => $text,
            ]);
            throw $e;
        }
    }

    /**
     * Infer category for a given description and amount
     */
    public function inferCategory(string $description, ?float $amount = null): array
    {
        $categories = $this->getCategoryList();
        $amountContext = $amount ? " Amount: $amount MXN." : '';

        $prompt = "Analyze this expense description and infer the most appropriate category:

Description: {$description}{$amountContext}

Available categories: ".implode(', ', $categories).'

Return ONLY valid JSON:
{
    "category_slug": "most_appropriate_category",
    "confidence": 0.95,
    "reasoning": "brief explanation"
}

Consider Mexican context and common spending patterns. Categories should match exactly from the available list.';

        try {
            $response = $this->makeApiCall($prompt);
            $result = $this->parseJsonResponse($response);

            // Find category ID by slug
            $category = Category::where('slug', $result['category_slug'] ?? '')->first();

            return [
                'category_id' => $category?->id,
                'confidence' => $result['confidence'] ?? 0.5,
                'reasoning' => $result['reasoning'] ?? '',
            ];
        } catch (\Exception $e) {
            Log::error('OpenAI inferCategory failed', [
                'error' => $e->getMessage(),
                'description' => $description,
                'amount' => $amount,
            ]);

            // Return default category on error
            $defaultCategory = Category::where('slug', 'uncategorized')->first();

            return [
                'category_id' => $defaultCategory?->id,
                'confidence' => 0.0,
                'reasoning' => 'Failed to infer category',
            ];
        }
    }

    /**
     * Process OCR text from receipt images
     */
    public function processImageText(string $ocrText): array
    {
        $prompt = "Extract expense information from this receipt OCR text and return ONLY valid JSON:

OCR Text:
{$ocrText}

Expected format:
{
    \"amount\": 123.45,
    \"description\": \"purchase summary\",
    \"merchant_name\": \"store name\",
    \"date\": \"YYYY-MM-DD\",
    \"items\": [
        {\"name\": \"item name\", \"price\": 12.34}
    ],
    \"tax\": 12.34,
    \"total\": 123.45,
    \"confidence\": 0.95
}

Rules:
- Extract the total amount (look for TOTAL, IMPORTE, etc.)
- Identify merchant/store name from header
- Extract date from receipt
- List main items if visible
- Extract tax if shown
- Default currency is MXN
- Handle common Mexican receipt formats";

        try {
            $response = $this->makeApiCall($prompt);
            $data = $this->parseJsonResponse($response);

            // Convert to expense format
            return [
                'amount' => $data['total'] ?? $data['amount'] ?? 0,
                'description' => $data['description'] ?? 'Receipt from '.($data['merchant_name'] ?? 'Unknown'),
                'merchant_name' => $data['merchant_name'] ?? null,
                'date' => $data['date'] ?? date('Y-m-d'),
                'confidence' => $data['confidence'] ?? 0.7,
                'items' => $data['items'] ?? [],
                'tax' => $data['tax'] ?? null,
            ];
        } catch (\Exception $e) {
            Log::error('OpenAI processImageText failed', [
                'error' => $e->getMessage(),
                'ocr_text_length' => strlen($ocrText),
            ]);
            throw $e;
        }
    }

    /**
     * Process voice transcription to extract expense data
     */
    public function processVoiceTranscription(string $transcription): array
    {
        $prompt = "Extract expense information from this voice transcription and return ONLY valid JSON:

Transcription: {$transcription}

Expected format:
{
    \"amount\": 123.45,
    \"description\": \"expense description\",
    \"category\": \"category_slug\",
    \"category_confidence\": 0.95,
    \"merchant_name\": \"merchant if mentioned\",
    \"date\": \"YYYY-MM-DD\",
    \"confidence\": 0.95
}

Rules:
- Handle informal speech patterns
- Extract numbers spoken in Spanish or English (e.g., 'doscientos pesos' = 200)
- Default date is today: ".date('Y-m-d').'
- Consider Mexican colloquialisms
- Default currency is MXN
- Be flexible with grammar and word order';

        try {
            $response = $this->makeApiCall($prompt);
            $data = $this->parseJsonResponse($response);

            return $this->normalizeExpenseData($data);
        } catch (\Exception $e) {
            Log::error('OpenAI processVoiceTranscription failed', [
                'error' => $e->getMessage(),
                'transcription' => $transcription,
            ]);
            throw $e;
        }
    }

    /**
     * Make API call to OpenAI
     */
    private function makeApiCall(string $prompt): array
    {
        $response = Http::timeout(30)
            ->retry(3, 100)
            ->withHeaders([
                'Authorization' => 'Bearer '.$this->apiKey,
                'Content-Type' => 'application/json',
            ])
            ->post($this->apiUrl, [
                'model' => $this->model,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are a helpful assistant that extracts structured data from text. Always respond with valid JSON only, no additional text.',
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt,
                    ],
                ],
                'max_tokens' => $this->maxTokens,
                'temperature' => $this->temperature,
            ]);

        if (! $response->successful()) {
            throw new \Exception('OpenAI API request failed: '.$response->body());
        }

        return $response->json();
    }

    /**
     * Parse JSON response from OpenAI
     */
    private function parseJsonResponse(array $response): array
    {
        $content = $response['choices'][0]['message']['content'] ?? '';

        // Try to extract JSON from the response
        $jsonStart = strpos($content, '{');
        $jsonEnd = strrpos($content, '}');

        if ($jsonStart !== false && $jsonEnd !== false) {
            $jsonString = substr($content, $jsonStart, $jsonEnd - $jsonStart + 1);
            $data = json_decode($jsonString, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                return $data;
            }
        }

        throw new \Exception('Failed to parse JSON from OpenAI response');
    }

    /**
     * Get cached category list
     */
    private function getCategoryList(): array
    {
        return Cache::remember('category_slugs', 3600, function () {
            return Category::where('is_active', true)
                ->pluck('slug')
                ->toArray();
        });
    }

    /**
     * Normalize expense data from various formats
     */
    private function normalizeExpenseData(array $data): array
    {
        // Ensure required fields
        $normalized = [
            'amount' => floatval($data['amount'] ?? 0),
            'description' => $data['description'] ?? '',
            'date' => $data['date'] ?? date('Y-m-d'),
            'currency' => $data['currency'] ?? 'MXN',
            'confidence' => floatval($data['confidence'] ?? 0.8),
        ];

        // Add optional fields if present
        if (isset($data['merchant_name'])) {
            $normalized['merchant_name'] = $data['merchant_name'];
        }

        if (isset($data['category'])) {
            $category = Category::where('slug', $data['category'])->first();
            if ($category) {
                $normalized['category_id'] = $category->id;
                $normalized['category_confidence'] = floatval($data['category_confidence'] ?? 0.8);
            }
        }

        // Sanitize the data
        $normalized = ExpenseDataValidator::sanitize($normalized);

        // Validate the data
        $errors = ExpenseDataValidator::validate($normalized);
        if (! empty($errors)) {
            Log::warning('OpenAI data validation errors', [
                'errors' => $errors,
                'data' => $normalized,
            ]);
        }

        return $normalized;
    }
}
