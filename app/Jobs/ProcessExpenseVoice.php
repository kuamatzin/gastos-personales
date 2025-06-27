<?php

namespace App\Jobs;

use App\Models\Expense;
use App\Services\CategoryInferenceService;
use App\Services\CategoryLearningService;
use App\Services\OpenAIService;
use App\Services\TelegramService;
use App\Services\SpeechToTextService;
use Illuminate\Support\Facades\DB;

class ProcessExpenseVoice extends BaseExpenseProcessor
{
    protected string $fileId;
    protected ?string $tempFile = null;

    /**
     * Create a new job instance.
     */
    public function __construct(string $userId, string $fileId, int $messageId)
    {
        $this->userId = $userId;
        $this->fileId = $fileId;
        $this->messageId = $messageId;
    }

    /**
     * Execute the job.
     */
    public function handle(
        SpeechToTextService $speechService,
        OpenAIService $openAIService,
        CategoryInferenceService $categoryService,
        CategoryLearningService $learningService,
        TelegramService $telegramService
    ): void {
        $this->logStart('voice', ['file_id' => $this->fileId]);
        
        try {
            $user = $this->getUser();
            
            // Step 1: Download voice file from Telegram
            $this->tempFile = $this->downloadTelegramFile($this->fileId);
            
            // Step 2: Transcribe voice to text (Telegram sends OGG files)
            $transcriptionResult = $speechService->processTelegramAudio($this->tempFile);
            $transcription = $transcriptionResult['transcript'] ?? $transcriptionResult['text'] ?? '';
            
            // Log transcription result for debugging
            \Log::info('Voice transcription result', [
                'file_id' => $this->fileId,
                'transcription' => $transcription,
                'confidence' => $transcriptionResult['confidence'] ?? 0,
                'language' => $transcriptionResult['language'] ?? 'unknown',
                'full_result' => $transcriptionResult
            ]);
            
            if (empty($transcription)) {
                // Send helpful message to user
                $telegramService->sendMessage(
                    $this->userId,
                    "❌ I couldn't understand the voice message. Please try speaking more clearly or send the expense as text.\n\n" .
                    "Example: \"200 pesos for lunch at restaurant\""
                );
                
                throw new \Exception('Voice transcription returned empty text');
            }
            
            // Step 3: Extract expense data using OpenAI
            $expenseData = $openAIService->extractExpenseData($transcription);
            
            // Step 4: Infer category if not already set
            if (!isset($expenseData['category_id'])) {
                $categoryInference = $categoryService->inferCategory(
                    $user,
                    $expenseData['description'],
                    $expenseData['amount']
                );
                
                $expenseData['category_id'] = $categoryInference['category_id'];
                $expenseData['category_confidence'] = $categoryInference['confidence'];
                $expenseData['inference_method'] = $categoryInference['method'];
            }
            
            // Step 5: Create pending expense record
            $expense = DB::transaction(function () use ($user, $expenseData, $transcription) {
                return Expense::create([
                    'user_id' => $user->id,
                    'amount' => $expenseData['amount'],
                    'currency' => $expenseData['currency'] ?? 'MXN',
                    'description' => $expenseData['description'],
                    'category_id' => $expenseData['category_id'],
                    'suggested_category_id' => $expenseData['category_id'],
                    'expense_date' => $expenseData['date'] ?? now()->toDateString(),
                    'raw_input' => $transcription,
                    'confidence_score' => $expenseData['confidence'] ?? 0.8,
                    'category_confidence' => $expenseData['category_confidence'] ?? 0.8,
                    'input_type' => 'voice',
                    'status' => 'pending',
                    'merchant_name' => $expenseData['merchant_name'] ?? null,
                    'metadata' => [
                        'voice_file_id' => $this->fileId,
                        'transcription' => $transcription,
                    ],
                ]);
            });
            
            // Step 6: Store context for confirmation handling
            $this->storeContext([
                'expense_id' => $expense->id,
                'expense_data' => $expenseData,
                'original_file_id' => $this->fileId,
                'transcription' => $transcription,
            ]);
            
            // Step 7: Send confirmation message with category
            $telegramService->sendExpenseConfirmationWithCategory(
                $this->userId,
                array_merge($expenseData, [
                    'expense_id' => $expense->id,
                    'transcription' => $transcription,
                ])
            );
            
            $this->logComplete('voice', [
                'expense_id' => $expense->id,
                'category_id' => $expense->category_id,
                'amount' => $expense->amount,
                'inference_method' => $expenseData['inference_method'] ?? 'unknown',
                'transcription_length' => strlen($transcription),
            ]);
            
        } catch (\Exception $e) {
            // Log the error
            \Log::error('Failed to process voice expense', [
                'user_id' => $this->userId,
                'file_id' => $this->fileId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            // Re-throw to trigger retry mechanism
            throw $e;
        } finally {
            // Clean up temporary file
            $this->cleanupFile($this->tempFile);
        }
    }

    /**
     * Get user-friendly failure message
     */
    protected function getFailureMessage(): string
    {
        return "I couldn't process your voice message. Please try again with clearer audio or send your expense as text.";
    }

    /**
     * Clean up resources on job deletion
     */
    public function __destruct()
    {
        $this->cleanupFile($this->tempFile);
    }
}