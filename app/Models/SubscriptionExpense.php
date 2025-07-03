<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SubscriptionExpense extends Model
{
    use HasFactory;

    protected $fillable = [
        'subscription_id',
        'expense_id',
        'charge_date',
        'status',
    ];

    protected $casts = [
        'charge_date' => 'date',
    ];

    /**
     * Get the subscription
     */
    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * Get the expense
     */
    public function expense()
    {
        return $this->belongsTo(Expense::class);
    }
}