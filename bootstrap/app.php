<?php

use App\Jobs\SyncLiveMatchesJob;
use App\Jobs\SyncMatchOversJob;
use App\Jobs\SyncScorecardJob;
use App\Jobs\SyncSeriesDataJob;
use App\Jobs\SyncSquadJob;
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
    ->withCommands([
        __DIR__ . '/../app/Console/Commands',
    ])
    ->withSchedule(function (Schedule $schedule): void {
        $shouldRun = static fn(): bool => !app(PauseWindowService::class)->isPaused();

        // $schedule->job(new SyncSeriesDataJob())
        //     ->cron('0 0 */3 * *')
        //     ->withoutOverlapping()
        //     ->when($shouldRun);
        // $schedule->job(new SyncLiveMatchesJob())
        //     ->everyFiveMinutes()
        //     ->withoutOverlapping()
        //     ->when($shouldRun);

        // $schedule->job(new SyncMatchOversJob())
        //     ->everyMinute()
        //     ->withoutOverlapping()
        //     ->when($shouldRun);

        $schedule->job(new SyncScorecardJob())
            ->everyThirtySeconds()
            ->withoutOverlapping()
            ->when($shouldRun);

        $schedule->job(new SyncSquadJob())
            ->dailyAt('06:00')
            ->withoutOverlapping()
            ->when($shouldRun);

        $schedule->command('logs:cleanup')
            ->dailyAt('05:30')
            ->withoutOverlapping()
            ->when($shouldRun);
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'auth' => App\Http\Middleware\Authenticate::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {})->create();
