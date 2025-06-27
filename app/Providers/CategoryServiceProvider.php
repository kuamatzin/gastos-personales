<?php

namespace App\Providers;

use App\Services\CategoryInferenceService;
use App\Services\CategoryLearningService;
use App\Services\OpenAIService;
use Illuminate\Support\ServiceProvider;

class CategoryServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Register CategoryLearningService as singleton
        $this->app->singleton(CategoryLearningService::class, function ($app) {
            return new CategoryLearningService;
        });

        // Register CategoryInferenceService as singleton
        $this->app->singleton(CategoryInferenceService::class, function ($app) {
            return new CategoryInferenceService(
                $app->make(OpenAIService::class),
                $app->make(CategoryLearningService::class)
            );
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // You can add scheduled tasks here if needed
        // For example, to decay old learning entries periodically
    }
}
