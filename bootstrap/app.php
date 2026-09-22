<?php

use App\Console\AttendanceSchedule;
use App\Http\Middleware\EnsureMustChangePassword;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

// Real container env vars outrank every .env file, so --env=testing must drop them for .env.testing's database to be used.
if (PHP_SAPI === 'cli' && in_array('--env=testing', $_SERVER['argv'] ?? [], true)) {
    foreach (['DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_URL'] as $key) {
        unset($_SERVER[$key], $_ENV[$key]);
        putenv($key);
    }
}

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(fn (Schedule $schedule) => AttendanceSchedule::register($schedule))
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);

        // Global, not per-route: see the class docblock for why a route
        // middleware list is the wrong shape for this particular gate.
        $middleware->web(append: [EnsureMustChangePassword::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
