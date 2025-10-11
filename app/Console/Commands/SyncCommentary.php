<?php

namespace App\Console\Commands;

use App\Jobs\SyncCommentaryJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SyncCommentary extends Command
{
    protected $signature = 'app:commentary-sync {--matchId=* : Sync commentary for specific match IDs only.}';
    protected $description = 'Dispatches a queue job to refresh commentary snapshots for live matches.';

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
        $matchIds = $this->normalizeOptionValues('matchId');
        $runId    = (string) Str::uuid();

        SyncCommentaryJob::dispatch($matchIds, $runId);

        if (empty($matchIds)) {
            $message = 'Commentary sync job queued for live matches.';
        } else {
            $message = 'Commentary sync job queued for match IDs: ' . implode(', ', $matchIds) . '.';
        }

        if ($this->output !== null) {
            $this->info($message . " Run ID: {$runId}");
        }

        Log::info('SYNC-COMMENTARY: ' . $message, [
            'run_id'    => $runId,
            'match_ids' => $matchIds,
        ]);

        return self::SUCCESS;
    }
}
