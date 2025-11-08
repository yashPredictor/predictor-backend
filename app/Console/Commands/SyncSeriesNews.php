<?php

namespace App\Console\Commands;

use App\Jobs\SyncSeriesNewsJob;
use Illuminate\Console\Command;

class SyncSeriesNews extends Command
{
    protected $signature = 'app:series-news-sync {--seriesId=* : Sync news for specific series IDs (comma or space separated).}';
    protected $description = 'Fetches and stores series news data in Firestore.';

    public function handle(): int
    {
        $seriesIds = $this->normalizeSeriesIds();

        SyncSeriesNewsJob::dispatch($seriesIds);

        if ($this->output !== null) {
            if (empty($seriesIds)) {
                $this->info('Series news sync job queued for pending series.');
            } else {
                $this->info('Series news sync job queued for series IDs: ' . implode(', ', $seriesIds) . '.');
            }
        }

        return self::SUCCESS;
    }

    /**
     * @return string[]
     */
    private function normalizeSeriesIds(): array
    {
        if ($this->input === null) {
            return [];
        }

        $raw = $this->input->getOption('seriesId');
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

