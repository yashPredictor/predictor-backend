<?php

namespace App\Console\Commands;

use App\Services\AdminSettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CleanSyncLogs extends Command
{
    protected $signature = 'logs:cleanup';

    protected $description = 'Prune sync log tables using the configured retention window.';

    private const TABLES = [
        'series_sync_logs',
        'live_match_sync_logs',
        'match_overs_sync_logs',
        'match_info_sync_logs',
        'scorecard_sync_logs',
        'series_squad_sync_logs',
        'recent_match_status_logs',
        'squad_sync_logs',
    ];

    public function handle(AdminSettingsService $settings): int
    {
        $retentionDays = $settings->logRetentionDays();
        $retentionMinutes = $settings->logRetentionMinutes();
        Log::info(sprintf('Starting cleanup of sync logs older than %d days (%d minutes).', $retentionDays, $retentionMinutes));
        $now = CarbonImmutable::now();
        $cutoff = $now->subDays($retentionDays);
        $totalDeleted = 0;

        foreach (self::TABLES as $table) {
            $deleted = DB::table($table)
                ->where('created_at', '<', $cutoff)
                ->delete();

            $totalDeleted += $deleted;

            $this->line(sprintf('Table %s: deleted %d rows older than %s', $table, $deleted, $cutoff->toDateTimeString()));
        }

        $this->info(sprintf(
            'Cleanup finished. Total rows deleted: %d (retention %d days, %d minutes).',
            $totalDeleted,
            $retentionDays,
            $retentionMinutes
        ));

        return self::SUCCESS;
    }
}
