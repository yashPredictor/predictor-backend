<?php

namespace App\Support\Logging;

use Illuminate\Http\Client\Response;
use Throwable;

trait ApiLogging
{
    protected int $apiCallTotal = 0;

    /**
     * @var array<string, array{count:int, method:string, host:?string, path:?string}>
     */
    protected array $apiCallTracker = [];

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

    protected function recordApiCall(string $url, string $method = 'GET', ?string $tag = null): void
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
