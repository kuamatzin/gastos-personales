<?php

namespace App\Services;

use App\Models\InstallmentPlan;
use App\Models\User;
use App\Models\Expense;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InstallmentPlanService
{
    /**
     * Create a new installment plan
     */
    public function createPlan(User $user, array $installmentData, array $expenseData): InstallmentPlan
    {
        return DB::transaction(function () use ($user, $installmentData, $expenseData) {
            // Create the installment plan
            $plan = InstallmentPlan::create([
                'user_id' => $user->id,
                'category_id' => $expenseData['category_id'],
                'total_amount' => $installmentData['total_amount'],
                'monthly_amount' => $installmentData['monthly_amount'],
                'total_months' => $installmentData['months'],
                'remaining_months' => $installmentData['months'],
                'has_interest' => $installmentData['has_interest'],
                'description' => $expenseData['description'],
                'start_date' => $expenseData['date'] ?? now($user->getTimezone())->toDateString(),
                'next_payment_date' => $expenseData['date'] ?? now($user->getTimezone())->toDateString(),
                'status' => 'pending', // Requires user confirmation
                'metadata' => [
                    'pattern_matched' => $installmentData['pattern_matched'] ?? null,
                    'detection_confidence' => $installmentData['confidence'] ?? null,
                    'original_text' => $expenseData['raw_input'] ?? null,
                ],
            ]);

            Log::info('Installment plan created', [
                'plan_id' => $plan->id,
                'user_id' => $user->id,
                'total_amount' => $plan->total_amount,
                'months' => $plan->total_months,
                'has_interest' => $plan->has_interest,
            ]);

            return $plan;
        });
    }

    /**
     * Process the next payment for an installment plan
     */
    public function processNextPayment(InstallmentPlan $plan): ?Expense
    {
        if (!$plan->isActive() || !$plan->isPaymentDue()) {
            return null;
        }

        Log::info('Processing next installment payment', [
            'plan_id' => $plan->id,
            'installment_number' => $plan->getNextInstallmentNumber(),
            'amount' => $plan->monthly_amount,
        ]);

        $expense = $plan->createNextExpense();

        if ($expense) {
            // Send notification to user about automatic payment
            $this->sendPaymentNotification($plan, $expense);
        }

        return $expense;
    }

    /**
     * Generate upcoming payments for all active plans
     */
    public function generateUpcomingPayments(): array
    {
        $processedPlans = [];
        $plans = InstallmentPlan::paymentDue()->with('user')->get();

        Log::info('Processing due installment payments', [
            'plans_count' => $plans->count(),
        ]);

        foreach ($plans as $plan) {
            try {
                $expense = $this->processNextPayment($plan);
                if ($expense) {
                    $processedPlans[] = [
                        'plan_id' => $plan->id,
                        'expense_id' => $expense->id,
                        'amount' => $expense->amount,
                        'installment_number' => $expense->installment_number,
                    ];
                }
            } catch (\Exception $e) {
                Log::error('Failed to process installment payment', [
                    'plan_id' => $plan->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('Completed processing installment payments', [
            'processed_count' => count($processedPlans),
        ]);

        return $processedPlans;
    }

    /**
     * Activate an installment plan and create the first expense
     */
    public function activatePlan(InstallmentPlan $plan): Expense
    {
        return DB::transaction(function () use ($plan) {
            $plan->activate();

            // Create the first expense
            $firstExpense = $plan->createNextExpense();

            Log::info('Installment plan activated', [
                'plan_id' => $plan->id,
                'first_expense_id' => $firstExpense->id,
                'total_months' => $plan->total_months,
            ]);

            return $firstExpense;
        });
    }

    /**
     * Cancel an installment plan
     */
    public function cancelPlan(InstallmentPlan $plan, string $reason = null): void
    {
        DB::transaction(function () use ($plan, $reason) {
            $plan->cancel();

            Log::info('Installment plan cancelled', [
                'plan_id' => $plan->id,
                'reason' => $reason,
                'remaining_months' => $plan->remaining_months,
            ]);
        });
    }

    /**
     * Get installment plan summary for user
     */
    public function getPlanSummary(InstallmentPlan $plan): array
    {
        $paidInstallments = $plan->total_months - $plan->remaining_months;
        $upcomingDates = $plan->getUpcomingPaymentDates(3);

        return [
            'id' => $plan->id,
            'description' => $plan->description,
            'total_amount' => $plan->total_amount,
            'monthly_amount' => $plan->monthly_amount,
            'total_months' => $plan->total_months,
            'remaining_months' => $plan->remaining_months,
            'paid_installments' => $paidInstallments,
            'progress_percentage' => $plan->getProgressPercentage(),
            'has_interest' => $plan->has_interest,
            'status' => $plan->status,
            'next_payment_date' => $plan->next_payment_date,
            'upcoming_dates' => $upcomingDates,
            'total_paid' => $plan->getTotalPaidAmount(),
            'remaining_amount' => $plan->getRemainingAmount(),
        ];
    }

    /**
     * Get user's active installment plans
     */
    public function getUserActivePlans(User $user): array
    {
        $plans = $user->installmentPlans()
            ->active()
            ->with('category', 'expenses')
            ->orderBy('next_payment_date')
            ->get();

        return $plans->map(function ($plan) {
            return $this->getPlanSummary($plan);
        })->toArray();
    }

    /**
     * Get user's completed installment plans
     */
    public function getUserCompletedPlans(User $user): array
    {
        $plans = $user->installmentPlans()
            ->where('status', 'completed')
            ->with('category', 'expenses')
            ->orderBy('updated_at', 'desc')
            ->limit(10)
            ->get();

        return $plans->map(function ($plan) {
            return $this->getPlanSummary($plan);
        })->toArray();
    }

    /**
     * Check if user has too many active plans (optional limit)
     */
    public function hasActivePlanLimit(User $user, int $maxPlans = 10): bool
    {
        $activeCount = $user->installmentPlans()->active()->count();
        return $activeCount >= $maxPlans;
    }

    /**
     * Send payment notification to user
     */
    private function sendPaymentNotification(InstallmentPlan $plan, Expense $expense): void
    {
        try {
            $telegramService = app(TelegramService::class);
            
            $message = "💳 *Pago de mensualidad procesado*\n\n";
            $message .= "📋 {$plan->description}\n";
            $message .= "💰 Cantidad: $" . number_format($expense->amount, 2) . "\n";
            $message .= "📅 Mensualidad: {$expense->installment_number}/{$plan->total_months}\n";
            
            if ($plan->remaining_months > 0) {
                $nextDate = Carbon::parse($plan->next_payment_date)->format('d/m/Y');
                $message .= "⏭️ Próximo pago: {$nextDate}\n";
            } else {
                $message .= "✅ ¡Plan completado!\n";
            }

            $telegramService->sendMessage(
                (string) $plan->user->telegram_id,
                $message,
                ['parse_mode' => 'Markdown']
            );

        } catch (\Exception $e) {
            Log::error('Failed to send installment payment notification', [
                'plan_id' => $plan->id,
                'expense_id' => $expense->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Calculate installment plan affordability (basic check)
     */
    public function checkAffordability(User $user, float $monthlyAmount): array
    {
        // Get user's average monthly expenses for the last 3 months
        $threeMonthsAgo = now($user->getTimezone())->subMonths(3);
        
        $recentExpenses = $user->expenses()
            ->where('expense_date', '>=', $threeMonthsAgo)
            ->where('status', 'confirmed')
            ->sum('amount');
        
        $monthlyAverage = $recentExpenses / 3;
        
        // Simple affordability check (installment should not exceed 30% of average monthly expenses)
        $affordabilityRatio = $monthlyAverage > 0 ? ($monthlyAmount / $monthlyAverage) : 1;
        $isAffordable = $affordabilityRatio <= 0.3;
        
        return [
            'is_affordable' => $isAffordable,
            'affordability_ratio' => $affordabilityRatio,
            'monthly_average' => $monthlyAverage,
            'installment_amount' => $monthlyAmount,
            'recommendation' => $this->getAffordabilityRecommendation($affordabilityRatio),
        ];
    }

    /**
     * Get affordability recommendation
     */
    private function getAffordabilityRecommendation(float $ratio): string
    {
        if ($ratio <= 0.15) {
            return 'Excelente - Esta mensualidad se ajusta perfectamente a tu presupuesto';
        } elseif ($ratio <= 0.3) {
            return 'Bueno - Esta mensualidad es manejable según tus gastos';
        } elseif ($ratio <= 0.5) {
            return 'Cuidado - Esta mensualidad podría ser alta para tu presupuesto';
        } else {
            return 'Riesgo - Esta mensualidad podría afectar significativamente tu presupuesto';
        }
    }
}