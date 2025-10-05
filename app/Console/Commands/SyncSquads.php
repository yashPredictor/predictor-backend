<?php

namespace App\Console\Commands;

use App\Jobs\SyncSquadJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SyncSquads extends Command
{
    protected $signature = 'app:squads-sync {--matchId=* : Sync squads for specific match IDs only.}';
    protected $description = 'Dispatches a queue job to refresh squads for upcoming matches.';

    /**
     * Normalise the option input into a flat, unique list of match IDs.
     *
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
        $matchIds = $this->normalizeOptionValues('matchId');
        $runId    = (string) Str::uuid();

        SyncSquadJob::dispatch($matchIds, $runId);

        if (empty($matchIds)) {
            $message = 'Squad sync job queued for all upcoming matches.';
        } else {
            $message = 'Squad sync job queued for match IDs: ' . implode(', ', $matchIds) . '.';
        }

        $this->info($message . " Run ID: {$runId}");

        Log::info('SYNC-SQUAD: ' . $message, [
            'run_id'    => $runId,
            'match_ids' => $matchIds,
        ]);

        return self::SUCCESS;
    }
}
