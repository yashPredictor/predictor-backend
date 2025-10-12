<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private array $tables = [
        'series_sync_logs',
        'live_match_sync_logs',
        'match_overs_sync_logs',
        'match_info_sync_logs',
        'scorecard_sync_logs',
        'series_squad_sync_logs',
        'recent_match_status_logs',
        'squad_sync_logs',
        'commentary_sync_logs',
        'series_stats_sync_logs',
        'series_venues_sync_logs',
    ];

    public function up(): void
    {
        foreach ($this->tables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                $table->index('run_id', $tableName . '_run_id_idx');
                $table->index('created_at', $tableName . '_created_at_idx');
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                $table->dropIndex($tableName . '_run_id_idx');
                $table->dropIndex($tableName . '_created_at_idx');
            });
        }
    }
};
