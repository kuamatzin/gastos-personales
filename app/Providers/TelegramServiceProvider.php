<?php

namespace App\Providers;

use App\Services\TelegramService;
use App\Services\ExpenseConfirmationService;
use App\Services\CategoryLearningService;
use Illuminate\Support\ServiceProvider;

class TelegramServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(TelegramService::class, function ($app) {
            return new TelegramService();
        });

        $this->app->singleton(ExpenseConfirmationService::class, function ($app) {
            return new ExpenseConfirmationService(
                $app->make(TelegramService::class),
                $app->make(CategoryLearningService::class)
            );
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Register any Telegram-specific configurations or commands here
    }
}
