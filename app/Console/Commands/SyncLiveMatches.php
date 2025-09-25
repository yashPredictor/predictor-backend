<?php

namespace App\Console\Commands;

use App\Jobs\SyncLiveMatchesJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SyncLiveMatches extends Command
{
    protected $signature = 'app:sync-live-matches {--matchId=* : Only sync the provided match IDs.}';
    protected $description = 'Dispatches a queue job to sync live matches from Cricbuzz into Firestore.';

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
        $matchIds = $this->normalizeOptionValues('matchId');
        $runId    = (string) Str::uuid();

        SyncLiveMatchesJob::dispatch($matchIds, $runId);

        if (empty($matchIds)) {
            $message = 'Live matches sync job queued for all live matches.';
        } else {
            $message = 'Live matches sync job queued for match IDs: ' . implode(', ', $matchIds) . '.';
        }

        $this->info($message . " Run ID: {$runId}");

        Log::info('SYNC-LIVE-MATCHES: ' . $message, [
            'run_id'    => $runId,
            'match_ids' => $matchIds,
        ]);

        return 0;
    }
}
