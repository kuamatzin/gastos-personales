<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CategoryLearning extends Model
{
    protected $table = 'category_learning';

    protected $fillable = [
        'user_id',
        'keyword',
        'category_id',
        'confidence_weight',
        'usage_count',
        'last_used_at',
    ];

    protected $casts = [
        'last_used_at' => 'datetime',
        'confidence_weight' => 'float',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function incrementUsage(): void
    {
        $this->increment('usage_count');
        $this->confidence_weight = min(2.0, $this->confidence_weight + 0.1);
        $this->last_used_at = now();
        $this->save();
    }
}
