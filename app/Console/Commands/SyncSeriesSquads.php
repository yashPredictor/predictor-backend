<?php

namespace App\Console\Commands;

use App\Jobs\SyncSeriesSquadJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SyncSeriesSquads extends Command
{
    protected $signature = 'app:series-squads-sync {--seriesId=* : Sync squads for specific series IDs only.}';

    protected $description = 'Dispatches a queue job to fetch series squad information from Cricbuzz.';

    /**
     * @return string[]
     */
    private function normalizeOptionValues(string $optionName): array
    {
        $raw = $this->option($optionName);
        $values = is_array($raw) ? $raw : ($raw === null ? [] : [$raw]);

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

        SyncSeriesSquadJob::dispatch($seriesIds, $runId);

        if (empty($seriesIds)) {
            $message = 'Series squad sync job queued for eligible series within 30 days.';
        } else {
            $message = 'Series squad sync job queued for series IDs: ' . implode(', ', $seriesIds) . '.';
        }

        $this->info($message . " Run ID: {$runId}");

        Log::info('SYNC-SERIES-SQUADS: ' . $message, [
            'run_id'     => $runId,
            'series_ids' => $seriesIds,
        ]);

        return self::SUCCESS;
    }
}
