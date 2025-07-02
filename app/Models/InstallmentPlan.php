<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Carbon\Carbon;

class InstallmentPlan extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'category_id',
        'total_amount',
        'monthly_amount',
        'total_months',
        'remaining_months',
        'has_interest',
        'description',
        'start_date',
        'next_payment_date',
        'status',
        'metadata',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'monthly_amount' => 'decimal:2',
        'has_interest' => 'boolean',
        'start_date' => 'date',
        'next_payment_date' => 'date',
        'metadata' => 'array',
    ];

    /**
     * Get the user that owns the installment plan
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the category for this installment plan
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Get all expenses associated with this installment plan
     */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class)->orderBy('installment_number');
    }

    /**
     * Check if the installment plan is completed
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed' || $this->remaining_months <= 0;
    }

    /**
     * Check if the installment plan is active
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Check if a payment is due
     */
    public function isPaymentDue(): bool
    {
        return $this->isActive() && 
               $this->next_payment_date <= now($this->user->getTimezone())->toDateString();
    }

    /**
     * Get the next installment number
     */
    public function getNextInstallmentNumber(): int
    {
        return ($this->total_months - $this->remaining_months) + 1;
    }

    /**
     * Calculate total amount paid so far
     */
    public function getTotalPaidAmount(): float
    {
        return $this->expenses()->where('status', 'confirmed')->sum('amount');
    }

    /**
     * Calculate remaining amount to be paid
     */
    public function getRemainingAmount(): float
    {
        return $this->total_amount - $this->getTotalPaidAmount();
    }

    /**
     * Get progress percentage
     */
    public function getProgressPercentage(): float
    {
        $paidInstallments = $this->total_months - $this->remaining_months;
        return ($paidInstallments / $this->total_months) * 100;
    }

    /**
     * Create the next expense for this installment plan
     */
    public function createNextExpense(): ?Expense
    {
        if (!$this->isActive() || $this->remaining_months <= 0) {
            return null;
        }

        $installmentNumber = $this->getNextInstallmentNumber();
        
        $expense = Expense::create([
            'user_id' => $this->user_id,
            'installment_plan_id' => $this->id,
            'installment_number' => $installmentNumber,
            'amount' => $this->monthly_amount,
            'currency' => 'MXN',
            'description' => $this->description . " (Mensualidad {$installmentNumber}/{$this->total_months})",
            'category_id' => $this->category_id,
            'suggested_category_id' => $this->category_id,
            'expense_date' => $this->next_payment_date,
            'raw_input' => "Automatic installment payment",
            'confidence_score' => 1.0,
            'category_confidence' => 1.0,
            'input_type' => 'installment',
            'status' => 'confirmed', // Auto-confirm installment payments
            'confirmed_at' => now(),
        ]);

        // Update the installment plan
        $this->remaining_months--;
        $this->next_payment_date = Carbon::parse($this->next_payment_date)
            ->addMonth()
            ->toDateString();

        if ($this->remaining_months <= 0) {
            $this->status = 'completed';
        }

        $this->save();

        return $expense;
    }

    /**
     * Cancel the installment plan
     */
    public function cancel(): void
    {
        $this->status = 'cancelled';
        $this->save();

        // Optionally, you might want to handle pending expenses
        $this->expenses()->where('status', 'pending')->delete();
    }

    /**
     * Activate the installment plan
     */
    public function activate(): void
    {
        $this->status = 'active';
        $this->save();
    }

    /**
     * Get a human-readable description of the installment plan
     */
    public function getFormattedDescription(): string
    {
        $interestText = $this->has_interest ? 'con intereses' : 'sin intereses';
        return "{$this->description} - {$this->total_months} meses {$interestText}";
    }

    /**
     * Get upcoming payment dates
     */
    public function getUpcomingPaymentDates(int $count = 3): array
    {
        $dates = [];
        $currentDate = Carbon::parse($this->next_payment_date);
        
        for ($i = 0; $i < min($count, $this->remaining_months); $i++) {
            $dates[] = $currentDate->copy()->addMonths($i);
        }
        
        return $dates;
    }

    /**
     * Scope for active plans
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope for plans with due payments
     */
    public function scopePaymentDue($query)
    {
        return $query->active()->where('next_payment_date', '<=', now()->toDateString());
    }
}