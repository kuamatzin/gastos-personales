<?php

namespace App\Telegram\Commands;

use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class InstallmentExpensesCommand extends Command
{
    protected string $command = 'installment_expenses';
    
    protected string $description = 'View your recent installment expenses';

    public function handle(array $message, string $params = ''): void
    {
        $chatId = $message['chat']['id'];
        
        try {
            // Get the date range (current month by default)
            $startDate = Carbon::now($this->user->getTimezone())->startOfMonth();
            $endDate = Carbon::now($this->user->getTimezone())->endOfMonth();
            
            // Get installment expenses for the period
            $installmentExpenses = $this->user->expenses()
                ->whereNotNull('installment_plan_id')
                ->whereBetween('expense_date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')])
                ->where('status', 'confirmed')
                ->with(['installmentPlan', 'category'])
                ->orderBy('expense_date', 'desc')
                ->get();
            
            if ($installmentExpenses->isEmpty()) {
                $this->telegram->sendMessage(
                    $chatId,
                    trans('telegram.no_installment_expenses', [], $this->user->language),
                    ['parse_mode' => 'Markdown']
                );
                return;
            }
            
            // Build message with installment expenses
            $message = trans('telegram.installment_expenses_header', [
                'month' => $startDate->translatedFormat('F Y')
            ], $this->user->language) . "\n\n";
            
            $totalAmount = 0;
            $byPlan = [];
            
            // Group by installment plan
            foreach ($installmentExpenses as $expense) {
                $planId = $expense->installment_plan_id;
                if (!isset($byPlan[$planId])) {
                    $byPlan[$planId] = [
                        'plan' => $expense->installmentPlan,
                        'expenses' => [],
                        'total' => 0
                    ];
                }
                $byPlan[$planId]['expenses'][] = $expense;
                $byPlan[$planId]['total'] += $expense->amount;
                $totalAmount += $expense->amount;
            }
            
            // Display by plan
            foreach ($byPlan as $planData) {
                $plan = $planData['plan'];
                $planExpenses = $planData['expenses'];
                $planTotal = $planData['total'];
                
                // Plan header
                $message .= "📦 *{$plan->description}*\n";
                $progress = $plan->getNextInstallmentNumber() - 1;
                $message .= "   📊 " . trans('telegram.installment_progress', [
                    'current' => $progress,
                    'total' => $plan->total_months
                ], $this->user->language) . "\n";
                
                // Expenses for this plan
                foreach ($planExpenses as $expense) {
                    $date = Carbon::parse($expense->expense_date)->format('d/m');
                    $message .= "   • {$date} - $" . number_format($expense->amount, 2);
                    $message .= " (" . trans('telegram.installment_number', [
                        'number' => $expense->installment_number
                    ], $this->user->language) . ")\n";
                }
                
                $message .= "   💰 " . trans('telegram.subtotal', [], $this->user->language) . 
                           ": $" . number_format($planTotal, 2) . "\n\n";
            }
            
            // Summary
            $message .= "━━━━━━━━━━━━━━━\n";
            $message .= "💳 " . trans('telegram.total_installment_payments', [
                'count' => $installmentExpenses->count(),
                'amount' => number_format($totalAmount, 2)
            ], $this->user->language);
            
            // Add percentage of total expenses
            $totalMonthExpenses = $this->user->expenses()
                ->whereBetween('expense_date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')])
                ->where('status', 'confirmed')
                ->sum('amount');
            
            if ($totalMonthExpenses > 0) {
                $percentage = round(($totalAmount / $totalMonthExpenses) * 100, 1);
                $message .= "\n📊 " . trans('telegram.percentage_of_total', [
                    'percentage' => $percentage
                ], $this->user->language);
            }
            
            $this->telegram->sendMessage(
                $chatId,
                $message,
                ['parse_mode' => 'Markdown']
            );
            
            Log::info('Installment expenses command executed', [
                'user_id' => $this->user->id,
                'expenses_count' => $installmentExpenses->count(),
                'total_amount' => $totalAmount,
            ]);
            
            return;
            
        } catch (\Exception $e) {
            Log::error('Failed to list installment expenses', [
                'error' => $e->getMessage(),
                'user_id' => $this->user->id,
            ]);
            
            $this->telegram->sendMessage(
                $chatId,
                trans('telegram.error_occurred', [], $this->user->language)
            );
            
            return;
        }
    }
}