<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'telegram_id',
        'telegram_username',
        'telegram_first_name',
        'telegram_last_name',
        'is_active',
        'preferences',
        'timezone',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'preferences' => 'array',
        ];
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function categoryLearning(): HasMany
    {
        return $this->hasMany(CategoryLearning::class);
    }

    /**
     * Get the user's timezone.
     */
    public function getTimezone(): string
    {
        return $this->timezone ?? 'America/Mexico_City';
    }

    /**
     * Get expenses for a specific date in the user's timezone.
     */
    public function expensesForDate(\DateTime|string $date): HasMany
    {
        if (is_string($date)) {
            $date = new \DateTime($date, new \DateTimeZone($this->getTimezone()));
        }
        
        return $this->expenses()->whereDate('expense_date', $date->format('Y-m-d'));
    }

    /**
     * Get today's expenses in the user's timezone.
     */
    public function expensesToday(): HasMany
    {
        $today = now($this->getTimezone())->startOfDay();
        return $this->expensesForDate($today);
    }

    /**
     * Get this month's expenses in the user's timezone.
     */
    public function expensesThisMonth(): HasMany
    {
        $now = now($this->getTimezone());
        return $this->expenses()
            ->whereYear('expense_date', $now->year)
            ->whereMonth('expense_date', $now->month);
    }

    /**
     * Get this week's expenses in the user's timezone.
     */
    public function expensesThisWeek(): HasMany
    {
        $now = now($this->getTimezone());
        $startOfWeek = $now->copy()->startOfWeek();
        $endOfWeek = $now->copy()->endOfWeek();
        
        return $this->expenses()
            ->whereBetween('expense_date', [$startOfWeek->format('Y-m-d'), $endOfWeek->format('Y-m-d')]);
    }
}
