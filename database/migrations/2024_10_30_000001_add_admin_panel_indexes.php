<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Tables backing the cron dashboard views.
     *
     * @var array<int, string>
     */
    private array $syncLogTables = [
        'series_sync_logs',
        'live_match_sync_logs',
        'match_overs_sync_logs',
        'match_info_sync_logs',
        'scorecard_sync_logs',
        'series_squad_sync_logs',
        'recent_match_status_logs',
        'squad_sync_logs',
        'squad_sync_playing_xii_logs',
        'commentary_sync_logs',
        'series_stats_sync_logs',
        'series_venues_sync_logs',
    ];

    public function up(): void
    {
        foreach ($this->syncLogTables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                $table->index(['run_id', 'created_at'], $tableName . '_run_created_idx');
                $table->index(['status', 'created_at'], $tableName . '_status_created_idx');
                $table->index(['action', 'created_at'], $tableName . '_action_created_idx');
            });
        }

        Schema::table('api_request_logs', function (Blueprint $table) {
            $table->index('requested_at', 'api_request_logs_requested_at_idx');
            $table->index(['method', 'requested_at'], 'api_request_logs_method_requested_idx');
        });
    }

    public function down(): void
    {
        foreach ($this->syncLogTables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                $table->dropIndex($tableName . '_run_created_idx');
                $table->dropIndex($tableName . '_status_created_idx');
                $table->dropIndex($tableName . '_action_created_idx');
            });
        }

        Schema::table('api_request_logs', function (Blueprint $table) {
            $table->dropIndex('api_request_logs_requested_at_idx');
            $table->dropIndex('api_request_logs_method_requested_idx');
        });
    }
};

