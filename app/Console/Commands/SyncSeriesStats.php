<?php

namespace App\Console\Commands;

use App\Jobs\SyncSeriesStatsJob;
use App\Services\AdminSettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SyncSeriesStats extends Command
{
    protected $signature = 'app:series-stats-sync {--matchId=* : Sync stats for specific match IDs only.} {--seriesId=* : Sync stats for specific series IDs only.}';
    protected $description = 'Dispatches a queue job to refresh top stats for recently completed series.';

    /**
     * @return string[]
     */
    private function normalizeOptionValues(string $optionName): array
    {
        $raw = null;

        if ($this->input !== null) {
            $raw = $this->input->getOption($optionName);
        }

        if ($raw === null) {
            return [];
        }

        $values = is_array($raw) ? $raw : [$raw];

        $normalized = [];

        $flatten = function ($value) use (&$flatten, &$normalized) {
            if (is_array($value)) {
                foreach ($value as $nested) {
                    $flatten($nested);
                }
                return;
            }

            $stringValue = trim((string) $value);
            if ($stringValue === '') {
                return;
            }

            foreach (preg_split('/[\s,]+/', $stringValue, -1, PREG_SPLIT_NO_EMPTY) as $part) {
                $normalized[] = $part;
            }
        };

        foreach ($values as $value) {
            $flatten($value);
        }

        return array_values(array_unique($normalized));
    }

    public function handle(): int
    {
        $matchIds  = $this->normalizeOptionValues('matchId');
        $seriesIds = $this->normalizeOptionValues('seriesId');
        $runId     = (string) Str::uuid();

        /** @var AdminSettingsService $settings */
        $settings = app(AdminSettingsService::class);

        if (!$settings->isCronEnabled(SyncSeriesStatsJob::CRON_KEY)) {
            $message = 'Series stats sync skipped because the cron is paused via emergency controls.';
            if ($this->output !== null) {
                $this->warn($message);
            }
            Log::warning('SYNC-SERIES-STATS: ' . $message, [
                'match_ids' => $matchIds,
                'series_ids'=> $seriesIds,
            ]);
            return self::SUCCESS;
        }

        SyncSeriesStatsJob::dispatch($matchIds, $seriesIds, $runId);

        $messages = [];
        if (empty($matchIds) && empty($seriesIds)) {
            $messages[] = 'Series stats sync job queued for eligible completed matches.';
        } else {
            if (!empty($matchIds)) {
                $messages[] = 'match IDs: ' . implode(', ', $matchIds);
            }
            if (!empty($seriesIds)) {
                $messages[] = 'series IDs: ' . implode(', ', $seriesIds);
            }
        }

        $message = 'Series stats sync job queued';
        if (!empty($messages)) {
            $message .= ' (' . implode(' | ', $messages) . ')';
        }
        $message .= ". Run ID: {$runId}";

        if ($this->output !== null) {
            $this->info($message);
        }

        Log::info('SYNC-SERIES-STATS: ' . $message, [
            'run_id'    => $runId,
            'match_ids' => $matchIds,
            'series_ids'=> $seriesIds,
        ]);

        return self::SUCCESS;
    }
}
