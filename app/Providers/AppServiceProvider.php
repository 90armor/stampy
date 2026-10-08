<?php

namespace App\Providers;

use App\Models\Leave;
use App\Models\OvertimeRequest;
use App\Policies\ReportPolicy;
use Illuminate\Database\Eloquent\Relations\Relation;
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
        // Reports aren't models, so their abilities are gates (ReportPolicy).
        Gate::define('reports.overtime', [ReportPolicy::class, 'overtime']);

        // approval_steps.approvable_type stores 'leave' or 'overtime', not a
        // class name, so renaming or moving a model never strands existing rows. Deliberately morphMap(), not enforceMorphMap():
        // enforcing would require every morph model to be mapped, including
        // User for spatie/laravel-permission's model_has_roles, whose rows
        // already store 'App\Models\User'.
        Relation::morphMap([
            'leave' => Leave::class,
            'overtime' => OvertimeRequest::class,
        ]);
    }
}
