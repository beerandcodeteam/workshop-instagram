<?php

namespace App\Providers;

use App\Contracts\EmbeddingServiceContract;
use App\Contracts\RankingTraceLogger;
use App\Logging\ChannelRankingTraceLogger;
use App\Services\GeminiEmbeddingService;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(EmbeddingServiceContract::class, GeminiEmbeddingService::class);
        $this->app->bind(RankingTraceLogger::class, ChannelRankingTraceLogger::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Livewire::addNamespace(
            'pages',
            viewPath: resource_path('views/pages'),
            classNamespace: 'App\\Livewire\\Pages',
            classPath: app_path('Livewire/Pages'),
        );
    }
}
