<?php

use App\Http\Middleware\EnsureTeacherProfileComplete;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\RoleMiddleware;
use App\Http\Middleware\TrackAccountSession;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('account-sessions:close-idle')->everyMinute();
        $schedule->command('appointments:notify-overdue')->everyMinute()->withoutOverlapping();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_PROTO,
        );
        $middleware->redirectGuestsTo(fn () => route('home'));

        $middleware->web(append: [
            EnsureUserIsActive::class,
            EnsureTeacherProfileComplete::class,
            TrackAccountSession::class,
        ]);

        $middleware->alias([
            'role' => RoleMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
