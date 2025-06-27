<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Expense extends Model
{
    protected $fillable = [
        'user_id',
        'amount',
        'currency',
        'description',
        'category_id',
        'suggested_category_id',
        'expense_date',
        'raw_input',
        'confidence_score',
        'category_confidence',
        'input_type',
        'status',
        'merchant_name',
        'confirmed_at',
        'rejected_at',
        'rejection_reason',
        'metadata',
    ];

    protected $casts = [
        'expense_date' => 'date',
        'amount' => 'decimal:2',
        'confidence_score' => 'float',
        'category_confidence' => 'float',
        'confirmed_at' => 'datetime',
        'rejected_at' => 'datetime',
        'metadata' => 'array',
    ];

    const STATUS_PENDING = 'pending';

    const STATUS_CONFIRMED = 'confirmed';

    const STATUS_AUTO_CONFIRMED = 'auto_confirmed';

    const STATUS_REJECTED = 'rejected';

    const STATUS_NEEDS_REVIEW = 'needs_review';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function suggestedCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'suggested_category_id');
    }

    public function scopeConfirmed($query)
    {
        return $query->where('status', 'confirmed');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function getFormattedAmountAttribute(): string
    {
        return '$'.number_format($this->amount, 2).' '.$this->currency;
    }
}
