<?php

namespace App\Services;

use App\Models\SeriesSyncLog;
use Illuminate\Support\Str;

class SeriesSyncLogger
{
    public readonly string $runId;

    public function __construct(?string $runId = null)
    {
        $this->runId = $runId ?: (string) Str::uuid();
    }

    public function log(string $action, ?string $status, string $message, array $context = []): void
    {
        SeriesSyncLog::create([
            'run_id'    => $this->runId,
            'action'    => $action,
            'status'    => $status,
            'message'   => $message,
            'context'   => empty($context) ? null : $context,
            'created_at'=> now(),
        ]);
    }
}
