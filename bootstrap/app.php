<?php

use App\Console\AttendanceSchedule;
use App\Console\LeaveSchedule;
use App\Http\Middleware\EnsureMustChangePassword;
use App\Http\Middleware\EnsureStillEmployed;
use App\Support\SessionExpiry;
use Illuminate\Auth\AuthenticationException;
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
    ->withSchedule(function (Schedule $schedule): void {
        AttendanceSchedule::register($schedule);
        LeaveSchedule::register($schedule);
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);

        // Global, not per-route: see the class docblock for why a route
        // middleware list is the wrong shape for this particular gate.
        // EnsureStillEmployed first: a leaver is signed out before anything
        // else, a forced password change included (Phase 6b).
        $middleware->web(append: [EnsureStillEmployed::class, EnsureMustChangePassword::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // A session that ran out mid-use, and the first page after signing
        // in again (Phase 6b): see SessionExpiry. Null falls through to the
        // default rendering.
        $exceptions->render(fn (AuthenticationException $e, Request $request) => SessionExpiry::unauthenticated($request));
        $exceptions->render(fn (Throwable $e, Request $request) => SessionExpiry::forbiddenAfterSignIn($e, $request));
    })->create();
