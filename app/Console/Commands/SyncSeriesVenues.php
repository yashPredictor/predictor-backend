<?php

namespace App\Console\Commands;

use App\Jobs\SyncSeriesVenuesJob;
use App\Services\AdminSettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SyncSeriesVenues extends Command
{
    protected $signature = 'app:series-venues-sync {--seriesId=* : Sync venues for specific series IDs only.}';
    protected $description = 'Dispatches a queue job to refresh venues for upcoming series.';

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
        $seriesIds = $this->normalizeOptionValues('seriesId');
        $runId     = (string) Str::uuid();

        /** @var AdminSettingsService $settings */
        $settings = app(AdminSettingsService::class);

        if (!$settings->isCronEnabled(SyncSeriesVenuesJob::CRON_KEY)) {
            $message = 'Series venues sync skipped because the cron is paused via emergency controls.';
            if ($this->output !== null) {
                $this->warn($message);
            }
            Log::warning('SYNC-SERIES-VENUES: ' . $message, [
                'series_ids' => $seriesIds,
            ]);
            return self::SUCCESS;
        }

        SyncSeriesVenuesJob::dispatch($seriesIds, $runId)->onQueue('series');

        if (empty($seriesIds)) {
            $message = 'Series venues sync job queued for upcoming series within 30 days.';
        } else {
            $message = 'Series venues sync job queued for series IDs: ' . implode(', ', $seriesIds) . '.';
        }

        if ($this->output !== null) {
            $this->info($message . " Run ID: {$runId}");
        }

        // Log::info('SYNC-SERIES-VENUES: ' . $message, [
        //     'run_id' => $runId,
        //     'series_ids' => $seriesIds,
        // ]);

        return self::SUCCESS;
    }
}
