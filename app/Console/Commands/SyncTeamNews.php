<?php

namespace App\Console\Commands;

use App\Jobs\SyncTeamNewsJob;
use Illuminate\Console\Command;

class SyncTeamNews extends Command
{
    protected $signature = 'app:team-news-sync {--teamId=* : Sync news for specific team IDs (comma or space separated).}';
    protected $description = 'Fetches and stores team news data in Firestore.';

    public function handle(): int
    {
        $teamIds = $this->normalizeTeamIds();

        SyncTeamNewsJob::dispatch($teamIds);

        if ($this->output !== null) {
            if (empty($teamIds)) {
                $this->info('Team news sync job queued for all teams from teamsList/all-categories.');
            } else {
                $this->info('Team news sync job queued for team IDs: ' . implode(', ', $teamIds) . '.');
            }
        }

        return self::SUCCESS;
    }

    /**
     * @return string[]
     */
    private function normalizeTeamIds(): array
    {
        if ($this->input === null) {
            return [];
        }

        $raw = $this->input->getOption('teamId');
        $values = is_array($raw) ? $raw : [$raw];

        $normalized = [];
        foreach ($values as $value) {
            if (is_array($value)) {
                $value = implode(',', $value);
            }

            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }

            foreach (preg_split('/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY) as $part) {
                $normalized[] = $part;
            }
        }

        return array_values(array_unique($normalized));
    }
}

