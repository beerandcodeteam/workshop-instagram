<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
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
