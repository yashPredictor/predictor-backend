<?php

namespace App\Support\Logging;

use Illuminate\Http\Client\Response;
use Throwable;

trait ApiLogging
{
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
}
