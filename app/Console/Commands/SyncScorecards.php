<?php

namespace App\Console\Commands;

use App\Jobs\SyncScorecardJob;
use App\Services\AdminSettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SyncScorecards extends Command
{
    protected $signature = 'app:scorecards-sync {--matchId=* : Sync scorecards for specific match IDs only.}';
    protected $description = 'Dispatches a queue job to refresh scorecards for live matches.';

    /**
     * Normalise the option input into a flat, unique list of match IDs.
     *
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
        $matchIds = $this->normalizeOptionValues('matchId');
        $runId    = (string) Str::uuid();

        /** @var AdminSettingsService $settings */
        $settings = app(AdminSettingsService::class);

        if (!$settings->isCronEnabled(SyncScorecardJob::CRON_KEY)) {
            $message = 'Scorecard sync skipped because the cron is paused via emergency controls.';
            if ($this->output !== null) {
                $this->warn($message);
            }
            Log::warning('SYNC-SCORECARD: ' . $message, [
                'match_ids' => $matchIds,
            ]);
            return self::SUCCESS;
        }

        SyncScorecardJob::dispatch($matchIds, $runId);

        if (empty($matchIds)) {
            $message = 'Scorecard sync job queued for all live matches.';
        } else {
            $message = 'Scorecard sync job queued for match IDs: ' . implode(', ', $matchIds) . '.';
        }

        if ($this->output !== null) {
            $this->info($message . " Run ID: {$runId}");
        }

        // Log::info('SYNC-SCORECARD: ' . $message, [
        //     'run_id'    => $runId,
        //     'match_ids' => $matchIds,
        // ]);

        return self::SUCCESS;
    }
}
