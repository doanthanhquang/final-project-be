<?php

namespace App\Providers;

use App\Services\LLM\LLMServiceInterface;
use App\Services\LLM\OpenAIService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bind OpenAI service as the LLM provider
        $this->app->singleton(LLMServiceInterface::class, function ($app) {
            return new OpenAIService;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
