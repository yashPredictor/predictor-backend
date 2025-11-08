<?php

namespace App\Console\Commands;

use App\Jobs\SyncPlayerNewsJob;
use Illuminate\Console\Command;

class SyncPlayerNews extends Command
{
    protected $signature = 'app:player-news-sync {--playerId=* : Sync news for specific player IDs (comma or space separated).}';
    protected $description = 'Fetches and stores player news data in Firestore.';

    public function handle(): int
    {
        $playerIds = $this->normalizePlayerIds();

        SyncPlayerNewsJob::dispatch($playerIds);

        if ($this->output !== null) {
            if (empty($playerIds)) {
                $this->info('Player news sync job queued for all players in the Firestore players collection.');
            } else {
                $this->info('Player news sync job queued for player IDs: ' . implode(', ', $playerIds) . '.');
            }
        }

        return self::SUCCESS;
    }

    /**
     * @return string[]
     */
    private function normalizePlayerIds(): array
    {
        if ($this->input === null) {
            return [];
        }

        $raw = $this->input->getOption('playerId');
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

