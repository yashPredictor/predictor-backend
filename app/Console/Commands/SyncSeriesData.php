<?php

namespace App\Console\Commands;

use App\Jobs\SyncSeriesDataJob;
use App\Services\AdminSettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SyncSeriesData extends Command
{
    protected $signature = 'app:sync-series {--seriesId=*} {--matchId=*}';
    protected $description = 'Fetches all series and their matches from Cricbuzz API and stores them in Firestore.';

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

            $stringValue = trim((string)$value);
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
        $seriesIds = $this->normalizeOptionValues('seriesId');
        $matchIds  = $this->normalizeOptionValues('matchId');

        /** @var AdminSettingsService $settings */
        $settings = app(AdminSettingsService::class);

        if (!$settings->isCronEnabled(SyncSeriesDataJob::CRON_KEY)) {
            $message = 'Series sync skipped because the cron is paused via emergency controls.';
            if ($this->output !== null) {
                $this->warn($message);
            }
            Log::warning('SYNC-SERIES: ' . $message, [
                'series_ids' => $seriesIds,
                'match_ids' => $matchIds,
            ]);
            return self::SUCCESS;
        }

        $runId = (string) Str::uuid();
        SyncSeriesDataJob::dispatch($seriesIds, $matchIds, $runId)->onQueue('series');

        if ($this->output !== null) {
            $this->info("Series sync job queued. Run ID: {$runId}");
        }

        // Log::info('SYNC-SERIES: Series sync job queued', [
        //     'run_id' => $runId,
        //     'series_ids' => $seriesIds,
        //     'match_ids' => $matchIds,
        // ]);

        return self::SUCCESS;
    }

}
