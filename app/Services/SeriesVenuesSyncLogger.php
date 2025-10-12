<?php

namespace App\Services;

use App\Models\SeriesVenuesSyncLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SeriesVenuesSyncLogger
{
    public readonly string $runId;

    public function __construct(?string $runId = null)
    {
        $this->runId = $runId ?: (string) Str::uuid();
    }

    public function log(string $action, ?string $status, string $message, array $context = []): void
    {
        $payload = [
            'run_id'    => $this->runId,
            'action'    => $action,
            'status'    => $status,
            'message'   => $message,
            'context'   => empty($context) ? null : $context,
            'created_at'=> now(),
        ];

        SeriesVenuesSyncLog::create($payload);

        $this->logToDefaultChannel($status, $message, $context);
    }

    private function logToDefaultChannel(?string $status, string $message, array $context = []): void
    {
        $level = match ($status) {
            'success' => 'info',
            'warning' => 'warning',
            'error'   => 'error',
            default   => 'info',
        };

        $contextWithRun = array_merge(['run_id' => $this->runId], $context);

        Log::log($level, 'SYNC-SERIES-VENUES: ' . $message, $contextWithRun);
    }
}
