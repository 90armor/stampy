<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

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
        Gate::define('manage-employees', fn ($user) => $user->hasAnyRole(['admin', 'manager']));
        Gate::define('manage-departments', fn ($user) => $user->hasRole('admin'));
    }
}
