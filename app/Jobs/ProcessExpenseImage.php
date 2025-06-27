<?php

namespace App\Jobs;

use App\Models\Expense;
use App\Services\CategoryInferenceService;
use App\Services\CategoryLearningService;
use App\Services\OCRService;
use App\Services\OpenAIService;
use App\Services\TelegramService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessExpenseImage extends BaseExpenseProcessor
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
        OCRService $ocrService,
        OpenAIService $openAIService,
        CategoryInferenceService $categoryService,
        CategoryLearningService $learningService,
        TelegramService $telegramService
    ): void {
        $this->logStart('image', ['file_id' => $this->fileId]);

        try {
            $user = $this->getUser();

            // Step 1: Download image file from Telegram
            $this->tempFile = $this->downloadTelegramFile($this->fileId);

            // Step 2: Extract text from image using OCR
            Log::alert('Extracting text from image using OCR');
            $ocrResult = $ocrService->extractTextFromImage($this->tempFile);
            $extractedText = $ocrResult['text'] ?? '';

            if (empty($extractedText)) {
                throw new \Exception('OCR returned no text from image');
            }

            // Step 3: Extract expense data using OpenAI
            $expenseData = $openAIService->extractExpenseData($extractedText);

            // Step 4: Infer category if not already set
            if (! isset($expenseData['category_id'])) {
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
            $expense = DB::transaction(function () use ($user, $expenseData, $extractedText) {
                return Expense::create([
                    'user_id' => $user->id,
                    'amount' => $expenseData['amount'],
                    'currency' => $expenseData['currency'] ?? 'MXN',
                    'description' => $expenseData['description'],
                    'category_id' => $expenseData['category_id'],
                    'suggested_category_id' => $expenseData['category_id'],
                    'expense_date' => $expenseData['date'] ?? now()->toDateString(),
                    'raw_input' => $extractedText,
                    'confidence_score' => $expenseData['confidence'] ?? 0.7, // Lower confidence for OCR
                    'category_confidence' => $expenseData['category_confidence'] ?? 0.7,
                    'input_type' => 'image',
                    'status' => 'pending',
                    'merchant_name' => $expenseData['merchant_name'] ?? null,
                    'metadata' => [
                        'image_file_id' => $this->fileId,
                        'ocr_text' => $extractedText,
                        'receipt_number' => $expenseData['receipt_number'] ?? null,
                    ],
                ]);
            });

            // Step 6: Store context for confirmation handling
            $this->storeContext([
                'expense_id' => $expense->id,
                'expense_data' => $expenseData,
                'original_file_id' => $this->fileId,
                'ocr_text' => $extractedText,
            ]);

            // Step 7: Send confirmation message with category
            $telegramService->sendExpenseConfirmationWithCategory(
                $this->userId,
                array_merge($expenseData, [
                    'expense_id' => $expense->id,
                    'ocr_preview' => substr($extractedText, 0, 200).'...',
                ]),
                $user->language ?? 'es'
            );

            $this->logComplete('image', [
                'expense_id' => $expense->id,
                'category_id' => $expense->category_id,
                'amount' => $expense->amount,
                'inference_method' => $expenseData['inference_method'] ?? 'unknown',
                'ocr_text_length' => strlen($extractedText),
            ]);

        } catch (\Exception $e) {
            // Log the error
            \Log::error('Failed to process image expense', [
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
        return "I couldn't read the receipt from your image. Please make sure the image is clear and try again, or send the expense as text.";
    }

    /**
     * Clean up resources on job deletion
     */
    public function __destruct()
    {
        $this->cleanupFile($this->tempFile);
    }
}
