<?php

namespace App\Support\Logging;

use App\Models\ApiRequestLog;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

trait ApiLogging
{
    protected int $apiCallTotal = 0;

    /**
     * @var array<string, array{count:int, method:string, host:?string, path:?string}>
     */
    protected array $apiCallTracker = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    protected array $apiCallDetails = [];

    protected ?string $apiLoggingRunId = null;
    protected ?string $apiLoggingJobKey = null;

    protected function responseContext(?Response $response, array $extra = []): array
    {
        if ($response === null) {
            return $extra;
        }

        $body = null;

        try {
            $body = $response->json();
        } catch (Throwable $e) {
            $body = $response->body();
        }

        return array_merge($extra, [
            'api' => [
                'status'  => $response->status(),
                'headers' => $response->headers(),
                'body'    => $body,
            ],
        ]);
    }

    protected function exceptionContext(Throwable $exception, array $extra = []): array
    {
        return array_merge($extra, [
            'exception' => [
                'class'   => get_class($exception),
                'message' => $exception->getMessage(),
                'trace'   => $exception->getTraceAsString(),
            ],
        ]);
    }

    protected function initApiLoggingContext(?string $runId, string $jobKey): void
    {
        $this->apiLoggingRunId = $runId;
        $this->apiLoggingJobKey = $jobKey;
    }

    protected function recordApiCall(string $url, string $method = 'GET', ?string $tag = null): string
    {
        $method = strtoupper($method);
        $parts  = parse_url($url);
        $host   = $parts['host'] ?? null;
        $path   = $parts['path'] ?? null;

        $key = $tag ?? ($method . ' ' . ($host ? $host . ($path ?? '') : $url));

        if (!isset($this->apiCallTracker[$key])) {
            $this->apiCallTracker[$key] = [
                'count'  => 0,
                'method' => $method,
                'host'   => $host,
                'path'   => $path,
            ];
        }

        $this->apiCallTracker[$key]['count']++;
        $this->apiCallTotal++;

        $callId = (string) Str::uuid();

        $this->apiCallDetails[$callId] = [
            'job_key' => $this->apiLoggingJobKey,
            'run_id'  => $this->apiLoggingRunId,
            'tag'     => $tag,
            'method'  => $method,
            'url'     => $url,
            'host'    => $host,
            'path'    => $path,
            'started_at' => microtime(true),
            'requested_at' => Carbon::now(),
        ];

        return $callId;
    }

    protected function finalizeApiCall(string $callId, ?Response $response = null, ?Throwable $exception = null): void
    {
        if (!isset($this->apiCallDetails[$callId])) {
            return;
        }

        $details = $this->apiCallDetails[$callId];
        unset($this->apiCallDetails[$callId]);

        $durationMs = null;
        if (isset($details['started_at'])) {
            $durationMs = (int) max(0, round((microtime(true) - (float) $details['started_at']) * 1000));
        }

        $statusCode = $response?->status();
        $isError = false;
        $responseBytes = null;
        $exceptionClass = null;
        $exceptionMessage = null;

        if ($response !== null) {
            $responseBytes = strlen((string) $response->body());
            $isError = $statusCode !== null && $statusCode >= 400;
        }

        if ($exception !== null) {
            $isError = true;
            $exceptionClass = get_class($exception);
            $exceptionMessage = $exception->getMessage();
        }

        ApiRequestLog::create([
            'job_key' => $details['job_key'],
            'run_id' => $details['run_id'],
            'tag' => $details['tag'],
            'method' => $details['method'],
            'host' => $details['host'],
            'path' => $details['path'],
            'url' => $details['url'],
            'status_code' => $statusCode,
            'is_error' => $isError,
            'duration_ms' => $durationMs,
            'response_bytes' => $responseBytes,
            'exception_class' => $exceptionClass,
            'exception_message' => $exceptionMessage,
            'requested_at' => $details['requested_at'] ?? Carbon::now(),
        ]);
    }

    protected function getApiCallBreakdown(): array
    {
        $breakdown = [];

        foreach ($this->apiCallTracker as $label => $entry) {
            $breakdown[$label] = [
                'count'  => $entry['count'],
                'method' => $entry['method'],
                'host'   => $entry['host'],
                'path'   => $entry['path'],
            ];
        }

        return [
            'total'     => $this->apiCallTotal,
            'breakdown' => $breakdown,
        ];
    }
}
