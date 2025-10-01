<?php

namespace App\Support\Queue\Middleware;

use App\Services\PauseWindowService;
use Closure;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Support\Facades\Log;

class RespectPauseWindow
{
    public function __construct(private readonly PauseWindowService $pauseWindow)
    {
    }

    public function handle(object $job, Closure $next): void
    {
        if (! $this->pauseWindow->isPaused()) {
            $next($job);
            return;
        }

        $delay = $this->pauseWindow->secondsUntilResume();
        if ($delay <= 0) {
            $delay = 300;
        }

        $queueJob = $job instanceof JobContract ? $job : ($job->job ?? null);
        $jobName  = method_exists($job, 'displayName') ? $job->displayName() : get_class($job);

        Log::info('Queue job delayed due to pause window', [
            'job'   => $queueJob?->resolveName() ?? $jobName,
            'delay' => $delay,
        ]);

        if ($queueJob instanceof JobContract) {
            $queueJob->release($delay);
            return;
        }

        if (method_exists($job, 'release')) {
            $job->release($delay);
        }
    }
}
