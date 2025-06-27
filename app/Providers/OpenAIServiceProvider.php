<?php

namespace App\Providers;

use App\Services\OpenAIService;
use Illuminate\Support\ServiceProvider;

class OpenAIServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(OpenAIService::class, function ($app) {
            return new OpenAIService;
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Ensure OpenAI configuration is available
        if (! config('services.openai.key')) {
            \Log::warning('OpenAI API key is not configured. Please set OPENAI_API_KEY in your .env file.');
        }
    }
}
