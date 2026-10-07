<?php

namespace App\Providers;

use App\Models\Leave;
use Illuminate\Database\Eloquent\Relations\Relation;
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
        // approval_steps.approvable_type stores 'leave', not a class name, so
        // renaming or moving the model never strands existing rows. Phase 4
        // adds overtime here. Deliberately morphMap(), not enforceMorphMap():
        // enforcing would require every morph model to be mapped, including
        // User for spatie/laravel-permission's model_has_roles, whose rows
        // already store 'App\Models\User'.
        Relation::morphMap([
            'leave' => Leave::class,
        ]);
    }
}
