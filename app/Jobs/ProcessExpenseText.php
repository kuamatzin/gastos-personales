<?php

namespace App\Jobs;

use App\Models\Expense;
use App\Services\CategoryInferenceService;
use App\Services\CategoryLearningService;
use App\Services\OpenAIService;
use App\Services\TelegramService;
use App\Services\DateParserService;
use Illuminate\Support\Facades\DB;

class ProcessExpenseText extends BaseExpenseProcessor
{
    protected string $text;

    /**
     * Create a new job instance.
     */
    public function __construct(string $userId, string $text, int $messageId)
    {
        $this->userId = $userId;
        $this->text = $text;
        $this->messageId = $messageId;
    }

    /**
     * Execute the job.
     */
    public function handle(
        OpenAIService $openAIService,
        CategoryInferenceService $categoryService,
        CategoryLearningService $learningService,
        TelegramService $telegramService,
        DateParserService $dateParser
    ): void {
        // Handle special commands
        if ($this->text === 'delete_message') {
            $telegramService->deleteMessage($this->userId, $this->messageId);

            return;
        }

        $this->logStart('text', ['text' => $this->text]);

        try {
            $user = $this->getUser();

            // Step 1: Extract expense data using OpenAI
            $expenseData = $openAIService->extractExpenseData($this->text, $user->getTimezone());

            // Step 1.5: Validate/extract date using DateParser as fallback
            if (!isset($expenseData['date']) || $expenseData['date'] === now($user->getTimezone())->toDateString()) {
                // If OpenAI didn't find a date or used today's date, try our parser
                $parsedDate = $dateParser->extractDateFromText($this->text);
                if ($parsedDate) {
                    $expenseData['date'] = $parsedDate->toDateString();
                    \Log::info('Date parsed from text', [
                        'original_text' => $this->text,
                        'parsed_date' => $expenseData['date'],
                        'relative' => $dateParser->getRelativeDateDescription($parsedDate)
                    ]);
                }
            }

            // Step 2: Infer category if not already set
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

            // Step 3: Create pending expense record
            $expense = DB::transaction(function () use ($user, $expenseData) {
                return Expense::create([
                    'user_id' => $user->id,
                    'amount' => $expenseData['amount'],
                    'currency' => $expenseData['currency'] ?? 'MXN',
                    'description' => $expenseData['description'],
                    'category_id' => $expenseData['category_id'],
                    'suggested_category_id' => $expenseData['category_id'],
                    'expense_date' => $expenseData['date'] ?? now($user->getTimezone())->toDateString(),
                    'raw_input' => $this->text,
                    'confidence_score' => $expenseData['confidence'] ?? 0.8,
                    'category_confidence' => $expenseData['category_confidence'] ?? 0.8,
                    'input_type' => 'text',
                    'status' => 'pending',
                    'merchant_name' => $expenseData['merchant_name'] ?? null,
                ]);
            });

            // Step 4: Store context for confirmation handling
            $this->storeContext([
                'expense_id' => $expense->id,
                'expense_data' => $expenseData,
                'original_text' => $this->text,
            ]);

            // Step 5: Send confirmation message with category
            $telegramService->sendExpenseConfirmationWithCategory(
                $this->userId,
                array_merge($expenseData, ['expense_id' => $expense->id]),
                $user->language ?? 'es'
            );

            $this->logComplete('text', [
                'expense_id' => $expense->id,
                'category_id' => $expense->category_id,
                'amount' => $expense->amount,
                'inference_method' => $expenseData['inference_method'] ?? 'unknown',
            ]);

        } catch (\Exception $e) {
            // Log the error
            \Log::error('Failed to process text expense', [
                'user_id' => $this->userId,
                'text' => $this->text,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Re-throw to trigger retry mechanism
            throw $e;
        }
    }

    /**
     * Get user-friendly failure message
     */
    protected function getFailureMessage(): string
    {
        return "Please try sending your expense in a simpler format, like '150 lunch at restaurant'.";
    }
}
