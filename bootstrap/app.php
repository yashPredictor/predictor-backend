<?php

use App\Jobs\SyncLiveMatchesJob;
use App\Jobs\SyncMatchOversJob;
use App\Jobs\SyncScorecardJob;
use App\Jobs\MoveEndedMatchesToRecentJob;
use App\Jobs\SyncSeriesDataJob;
use App\Jobs\SyncCommentaryJob;
use App\Jobs\SyncSeriesSquadJob;
use App\Jobs\SyncSeriesVenuesJob;
use App\Jobs\SyncSquadJob;
use App\Jobs\SyncSquadsPlayingXIIJob;
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

        $schedule->job(new SyncLiveMatchesJob(), 'live')
            ->everyFifteenSeconds()
            ->withoutOverlapping()
            ->when($shouldRun);

        $schedule->job(new SyncMatchOversJob(), 'overs')
            ->everyFifteenSeconds()
            ->withoutOverlapping()
            ->when($shouldRun);

        $schedule->job(new SyncSeriesDataJob(), 'series')
            ->cron('0 11 */3 * *') 
            ->withoutOverlapping()
            ->when($shouldRun);

        $schedule->job(new SyncScorecardJob(), 'scorecards')
            ->everyFifteenSeconds()
            ->withoutOverlapping()
            ->when($shouldRun);

        $schedule->job(new SyncCommentaryJob(), 'commentary')
            ->everyFifteenSeconds()
            ->withoutOverlapping()
            ->when($shouldRun);

        $schedule->job(new SyncSquadJob(), 'squads')
            ->hourly()
            ->withoutOverlapping()
            ->when($shouldRun);
    
        $schedule->job(new SyncSquadsPlayingXIIJob(), 'squads')
            ->everyMinute()
            ->withoutOverlapping()
            ->when($shouldRun);

        $schedule->job(new SyncSeriesVenuesJob(), 'series')
            ->dailyAt('11:00')
            ->withoutOverlapping()
            ->when($shouldRun);

        $schedule->job(new MoveEndedMatchesToRecentJob())
            ->everyMinute()
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
    ->withExceptions(function (Exceptions $exceptions): void { })->create();
