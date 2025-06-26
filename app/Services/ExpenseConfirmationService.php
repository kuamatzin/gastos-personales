<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class ExpenseConfirmationService
{
    protected TelegramService $telegramService;
    protected CategoryLearningService $learningService;

    public function __construct(
        TelegramService $telegramService,
        CategoryLearningService $learningService
    ) {
        $this->telegramService = $telegramService;
        $this->learningService = $learningService;
    }

    /**
     * Process user confirmation of an expense
     */
    public function confirmExpense(User $user, int $expenseId): bool
    {
        $expense = Expense::where('user_id', $user->id)
            ->where('id', $expenseId)
            ->where('status', 'pending')
            ->first();

        if (!$expense) {
            Log::warning('Expense not found for confirmation', [
                'user_id' => $user->id,
                'expense_id' => $expenseId,
            ]);
            return false;
        }

        DB::transaction(function () use ($expense, $user) {
            // Update expense status
            $expense->update([
                'status' => 'confirmed',
                'confirmed_at' => now(),
            ]);

            // Learn from the confirmation if category was suggested
            if ($expense->category_id && $expense->description) {
                $this->learningService->learnFromUserChoice(
                    $user,
                    $expense->description,
                    $expense->category_id
                );
            }

            Log::info('Expense confirmed', [
                'expense_id' => $expense->id,
                'user_id' => $user->id,
                'category_id' => $expense->category_id,
            ]);
        });

        // Clear the context
        $this->clearContext($user->telegram_id, $expense->id);

        return true;
    }

    /**
     * Process user rejection/edit of an expense
     */
    public function rejectExpense(User $user, int $expenseId, ?string $reason = null): bool
    {
        $expense = Expense::where('user_id', $user->id)
            ->where('id', $expenseId)
            ->where('status', 'pending')
            ->first();

        if (!$expense) {
            return false;
        }

        // Update expense status
        $expense->update([
            'status' => 'rejected',
            'rejected_at' => now(),
            'rejection_reason' => $reason,
        ]);

        // Clear the context
        $this->clearContext($user->telegram_id, $expense->id);

        Log::info('Expense rejected', [
            'expense_id' => $expense->id,
            'user_id' => $user->id,
            'reason' => $reason,
        ]);

        return true;
    }

    /**
     * Update expense category based on user selection
     */
    public function updateCategory(User $user, int $expenseId, int $categoryId): bool
    {
        $expense = Expense::where('user_id', $user->id)
            ->where('id', $expenseId)
            ->whereIn('status', ['pending', 'confirmed'])
            ->first();

        if (!$expense) {
            return false;
        }

        DB::transaction(function () use ($expense, $user, $categoryId) {
            // Update expense
            $expense->update([
                'category_id' => $categoryId,
                'category_confidence' => 1.0, // User confirmed, so 100% confidence
                'status' => 'confirmed',
                'confirmed_at' => now(),
            ]);

            // Learn from user's category choice
            if ($expense->description) {
                $this->learningService->learnFromUserChoice(
                    $user,
                    $expense->description,
                    $categoryId
                );
            }

            Log::info('Expense category updated', [
                'expense_id' => $expense->id,
                'user_id' => $user->id,
                'old_category_id' => $expense->suggested_category_id,
                'new_category_id' => $categoryId,
            ]);
        });

        return true;
    }

    /**
     * Update expense amount
     */
    public function updateAmount(User $user, int $expenseId, float $amount): bool
    {
        $expense = Expense::where('user_id', $user->id)
            ->where('id', $expenseId)
            ->where('status', 'pending')
            ->first();

        if (!$expense) {
            return false;
        }

        $expense->update([
            'amount' => $amount,
            'confidence_score' => 1.0, // User confirmed, so 100% confidence
        ]);

        Log::info('Expense amount updated', [
            'expense_id' => $expense->id,
            'user_id' => $user->id,
            'old_amount' => $expense->getOriginal('amount'),
            'new_amount' => $amount,
        ]);

        return true;
    }

    /**
     * Get pending expenses for a user
     */
    public function getPendingExpenses(User $user, int $limit = 5): array
    {
        return $user->expenses()
            ->with('category.parent')
            ->pending()
            ->latest()
            ->limit($limit)
            ->get()
            ->map(function ($expense) {
                return [
                    'id' => $expense->id,
                    'amount' => $expense->amount,
                    'currency' => $expense->currency,
                    'description' => $expense->description,
                    'category' => $expense->category ? [
                        'id' => $expense->category->id,
                        'name' => $expense->category->name,
                        'icon' => $expense->category->icon,
                        'parent_name' => $expense->category->parent?->name,
                        'parent_icon' => $expense->category->parent?->icon,
                    ] : null,
                    'date' => $expense->expense_date,
                    'created_at' => $expense->created_at,
                    'confidence' => $expense->category_confidence,
                ];
            })
            ->toArray();
    }

    /**
     * Store expense context for callback handling
     */
    public function storeContext(string $chatId, int $expenseId, array $data): void
    {
        $key = "expense_context:{$chatId}:{$expenseId}";
        Redis::setex($key, 3600, json_encode($data)); // 1 hour TTL
    }

    /**
     * Get expense context
     */
    public function getContext(string $chatId, int $expenseId): ?array
    {
        $key = "expense_context:{$chatId}:{$expenseId}";
        $data = Redis::get($key);
        
        return $data ? json_decode($data, true) : null;
    }

    /**
     * Clear expense context
     */
    public function clearContext(string $chatId, int $expenseId): void
    {
        $key = "expense_context:{$chatId}:{$expenseId}";
        Redis::del($key);
    }

    /**
     * Handle confirmation timeout
     */
    public function handleConfirmationTimeout(User $user, int $expenseId): void
    {
        $expense = Expense::where('user_id', $user->id)
            ->where('id', $expenseId)
            ->where('status', 'pending')
            ->first();

        if (!$expense) {
            return;
        }

        // Auto-confirm expenses with high confidence after timeout
        if ($expense->category_confidence >= 0.8 && $expense->confidence_score >= 0.8) {
            $expense->update([
                'status' => 'auto_confirmed',
                'confirmed_at' => now(),
            ]);

            // Still learn from auto-confirmation
            if ($expense->category_id && $expense->description) {
                $this->learningService->learnFromUserChoice(
                    $user,
                    $expense->description,
                    $expense->category_id
                );
            }

            Log::info('Expense auto-confirmed after timeout', [
                'expense_id' => $expense->id,
                'user_id' => $user->id,
            ]);
        } else {
            // Mark as needs_review for low confidence
            $expense->update([
                'status' => 'needs_review',
            ]);

            Log::info('Expense marked for review after timeout', [
                'expense_id' => $expense->id,
                'user_id' => $user->id,
            ]);
        }

        // Clear the context
        $this->clearContext($user->telegram_id, $expense->id);
    }
}