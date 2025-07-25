<?php

namespace App\Jobs;

use App\Models\Expense;
use App\Services\CategoryInferenceService;
use App\Services\CategoryLearningService;
use App\Services\OpenAIService;
use App\Services\TelegramService;
use App\Services\DateParserService;
use Illuminate\Support\Facades\Cache;
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
        DateParserService $dateParser,
        \App\Services\InstallmentDetectionService $installmentService,
        \App\Services\InstallmentPlanService $installmentPlanService
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

            // Step 1.6: Detect if this is an installment purchase
            $installmentData = $installmentService->detectInstallments($this->text);
            if ($installmentData && $installmentData['is_installment']) {
                \Log::info('Installment detected', [
                    'user_id' => $user->id,
                    'installment_data' => $installmentData,
                    'original_text' => $this->text
                ]);
                
                // Store installment data for later use
                $expenseData['is_installment'] = true;
                $expenseData['installment_data'] = $installmentData;
                
                // For installments, we'll initially create just the first payment
                // The full plan will be created after user confirmation
                $expenseData['amount'] = $installmentData['monthly_amount'];
                $expenseData['original_amount'] = $installmentData['total_amount'];
            }

            // Step 1.7: Detect if this is a subscription
            $subscriptionService = new \App\Services\SubscriptionDetectionService();
            $subscriptionData = $subscriptionService->detectSubscription($this->text);
            if ($subscriptionData['is_subscription']) {
                \Log::info('Subscription detected', [
                    'user_id' => $user->id,
                    'subscription_data' => $subscriptionData,
                    'original_text' => $this->text
                ]);
                
                // Store subscription data for later use
                $expenseData['is_subscription'] = true;
                $expenseData['subscription_data'] = $subscriptionData;
                
                // Check if this matches an existing subscription
                $existingSubscription = $subscriptionService->checkExistingSubscription(
                    $user->id,
                    $expenseData['amount'],
                    $expenseData['merchant_name'] ?? null
                );
                
                if ($existingSubscription) {
                    $expenseData['existing_subscription'] = $existingSubscription;
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

            // Step 2.5: Ensure we have a valid category_id (fallback to uncategorized)
            if (!$expenseData['category_id']) {
                $uncategorizedCategory = \App\Models\Category::where('slug', 'uncategorized')->first();
                if ($uncategorizedCategory) {
                    $expenseData['category_id'] = $uncategorizedCategory->id;
                    $expenseData['category_confidence'] = 0.0;
                    $expenseData['inference_method'] = 'fallback';
                } else {
                    Log::error('No uncategorized category found and category inference returned null', [
                        'expense_data' => $expenseData,
                        'user_id' => $user->id,
                    ]);
                    throw new \Exception('Unable to assign category to expense');
                }
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
            $contextData = [
                'expense_id' => $expense->id,
                'expense_data' => $expenseData,
                'original_text' => $this->text,
            ];
            
            $this->storeContext($contextData);
            
            // Also store with expense ID for installment/subscription handling
            if (isset($expenseData['is_installment']) && $expenseData['is_installment']) {
                Cache::put("expense_context_{$expense->id}", $contextData, now()->addHour());
            } elseif (isset($expenseData['is_subscription']) && $expenseData['is_subscription']) {
                Cache::put("expense_context_{$expense->id}", $contextData, now()->addHour());
            }

            // Step 5: Send confirmation message
            if (isset($expenseData['is_installment']) && $expenseData['is_installment']) {
                // Send installment confirmation
                $telegramService->sendInstallmentConfirmation(
                    $this->userId,
                    array_merge($expenseData, ['expense_id' => $expense->id]),
                    $expenseData['installment_data'],
                    $user->language ?? 'es'
                );
            } elseif (isset($expenseData['is_subscription']) && $expenseData['is_subscription']) {
                // Send subscription confirmation
                $telegramService->sendSubscriptionConfirmation(
                    $this->userId,
                    array_merge($expenseData, ['expense_id' => $expense->id]),
                    $expenseData['subscription_data'],
                    $user->language ?? 'es'
                );
            } else {
                // Send regular expense confirmation
                $telegramService->sendExpenseConfirmationWithCategory(
                    $this->userId,
                    array_merge($expenseData, ['expense_id' => $expense->id]),
                    $user->language ?? 'es'
                );
            }

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
