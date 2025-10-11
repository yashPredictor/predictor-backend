<?php

namespace App\Console\Commands;

use App\Jobs\MoveEndedMatchesToRecentJob;
use App\Services\AdminSettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SyncRecentMatches extends Command
{
    protected $signature = 'app:recent-matches-sync';

    protected $description = 'Moves matches that ended more than 10 minutes ago into the recent state.';

    public function handle(): int
    {
        $runId = (string) Str::uuid();

        /** @var AdminSettingsService $settings */
        $settings = app(AdminSettingsService::class);

        if (!$settings->isCronEnabled(MoveEndedMatchesToRecentJob::CRON_KEY)) {
            $message = 'Recent matches sync skipped because the cron is paused via emergency controls.';
            if ($this->output !== null) {
                $this->warn($message);
            }
            Log::warning('SYNC-RECENT-MATCHES: ' . $message);
            return self::SUCCESS;
        }

        MoveEndedMatchesToRecentJob::dispatch($runId);

        $message = 'Recent matches sync job queued to promote completed matches into recent status.';

        if ($this->output !== null) {
            $this->info($message . " Run ID: {$runId}");
        }

        Log::info('SYNC-RECENT-MATCHES: ' . $message, [
            'run_id' => $runId,
        ]);

        return self::SUCCESS;
    }
}
