<?php

use App\Jobs\SyncLiveMatchesJob;
use App\Jobs\SyncMatchOversJob;
use App\Jobs\SyncSeriesDataJob;
use App\Services\PauseWindowService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule): void {
        $shouldRun = static fn (): bool => !app(PauseWindowService::class)->isPaused();

        $schedule->job(new SyncSeriesDataJob())
            ->cron('0 0 */3 * *')
            ->withoutOverlapping()
            ->when($shouldRun);

        $schedule->job(new SyncLiveMatchesJob())
            ->everyMinute()
            ->withoutOverlapping()
            ->when($shouldRun);

        $schedule->job(new SyncMatchOversJob())
            ->everyThirtySeconds()
            ->withoutOverlapping()
            ->when($shouldRun);
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'auth' => App\Http\Middleware\Authenticate::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        
    })->create();
