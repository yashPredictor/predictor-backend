<?php

namespace App\Jobs;

use App\Support\Queue\Middleware\RespectPauseWindow;
use Google\Cloud\Firestore\DocumentSnapshot;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Throwable;

class SyncSeriesNewsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const SERIES_COLLECTION = 'series';
    private const SERIES_NEWS_COLLECTION = 'seriesNews';

    public int $timeout = 600;
    public int $tries = 3;

    private ?FirestoreClient $firestore = null;
    private ?string $apiKey = null;
    private string $apiHost;
    private string $baseUrl;

    public function __construct(
        private readonly array $seriesIds = [],
    ) {
    }

    /**
     * @return string[]
     */
    public function middleware(): array
    {
        return [RespectPauseWindow::class];
    }

    public function handle(): void
    {
        $this->apiHost = config('services.cricbuzz.host', '139.59.8.120:8987');
        $this->baseUrl = sprintf('http://%s/news/v1/series/', $this->apiHost);

        try {
            $this->firestore = $this->initializeFirestoreClient();
        } catch (Throwable $e) {
            report($e);
            return;
        }

        $seriesIds = $this->resolveSeriesIds();
        if (empty($seriesIds) || $this->firestore === null) {
            return;
        }

        $headers = $this->buildHeaders();

        foreach ($seriesIds as $seriesId) {
            $payload = $this->fetchSeriesNews($seriesId, $headers);
            if ($payload === null) {
                continue;
            }

            $this->storeSeriesNews($seriesId, $payload);
        }
    }

    private function initializeFirestoreClient(): FirestoreClient
    {
        $firestoreConfig = config('services.firestore');
        $keyPath = $firestoreConfig['sa_json'] ?? null;
        $projectId = $firestoreConfig['project_id'] ?? null;

        if (!$projectId && $keyPath && is_file($keyPath)) {
            $json = json_decode(file_get_contents($keyPath), true);
            $projectId = $json['project_id'] ?? null;
        }

        if (!$projectId) {
            throw new \RuntimeException(
                'Firestore project id missing. Set FIRESTORE_PROJECT_ID or provide a service account JSON with project_id.'
            );
        }

        $options = ['projectId' => $projectId];
        if ($keyPath && is_file($keyPath)) {
            $options['keyFilePath'] = $keyPath;
        }

        $this->apiKey = config('services.cricbuzz.key');
        if (!$this->apiKey) {
            throw new \RuntimeException('Cricbuzz API key is not configured.');
        }

        return new FirestoreClient($options);
    }

    /**
     * @return string[]
     */
    private function resolveSeriesIds(): array
    {
        $explicit = array_values(array_filter(array_map(
            static fn($id) => trim((string) $id),
            $this->seriesIds
        )));

        if (!empty($explicit)) {
            return array_values(array_unique($explicit));
        }

        if ($this->firestore === null) {
            return [];
        }

        $pending = $this->fetchSeriesIdsWhereStartPending();
        if (!empty($pending)) {
            return $pending;
        }

        return $this->fetchUpcomingSeriesIds();
    }

    /**
     * @param array<string, string> $headers
     */
    private function fetchSeriesNews(string $seriesId, array $headers): ?array
    {
        $url = $this->baseUrl . $seriesId;

        try {
            $response = Http::withHeaders($headers)->get($url);
        } catch (Throwable $e) {
            report($e);
            return null;
        }

        if (!$response->successful()) {
            return null;
        }

        $payload = $response->json();
        if (!is_array($payload)) {
            return null;
        }

        if (array_key_exists('storyList', $payload)) {
            $payload['stories'] = $payload['storyList'];
            unset($payload['storyList']);
        } elseif (!array_key_exists('stories', $payload)) {
            $payload['stories'] = [];
        }

        return $payload;
    }

    /**
     * @return string[]
     */
    private function fetchSeriesIdsWhereStartPending(): array
    {
        if ($this->firestore === null) {
            return [];
        }

        try {
            $documents = $this->firestore
                ->collection(self::SERIES_COLLECTION)
                ->where('startDt', '==', 'pending')
                ->documents();
        } catch (Throwable $e) {
            report($e);
            return [];
        }

        $seriesIds = [];
        foreach ($documents as $snapshot) {
            $seriesId = $this->extractSeriesId($snapshot);
            if ($seriesId !== null) {
                $seriesIds[] = $seriesId;
            }
        }

        return array_values(array_unique($seriesIds));
    }

    /**
     * @return string[]
     */
    private function fetchUpcomingSeriesIds(): array
    {
        if ($this->firestore === null) {
            return [];
        }

        $now = now()->valueOf();

        try {
            $documents = $this->firestore
                ->collection(self::SERIES_COLLECTION)
                ->where('startDtTimestamp', '>=', $now)
                ->documents();
        } catch (Throwable $e) {
            report($e);
            return [];
        }

        $seriesIds = [];
        foreach ($documents as $snapshot) {
            if (!$snapshot->exists()) {
                continue;
            }

            $data = $snapshot->data();
            $start = (int) ($data['startDtTimestamp'] ?? 0);
            if ($start < $now) {
                continue;
            }

            $seriesId = $this->extractSeriesId($snapshot);
            if ($seriesId !== null) {
                $seriesIds[] = $seriesId;
            }
        }

        return array_values(array_unique($seriesIds));
    }

    private function extractSeriesId(DocumentSnapshot $snapshot): ?string
    {
        if (!$snapshot->exists()) {
            return null;
        }

        $data = $snapshot->data();
        $seriesId = (string) ($data['id'] ?? $snapshot->id());

        return $seriesId === '' ? null : $seriesId;
    }

    private function storeSeriesNews(string $seriesId, array $payload): void
    {
        if ($this->firestore === null) {
            return;
        }

        $documentPayload = $payload + [
            'seriesId' => $seriesId,
            'lastFetched' => now()->valueOf(),
        ];

        try {
            $this->firestore
                ->collection(self::SERIES_NEWS_COLLECTION)
                ->document($seriesId)
                ->set($documentPayload, ['merge' => true]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @return array<string, string>
     */
    private function buildHeaders(): array
    {
        return [
            'x-rapidapi-host' => $this->apiHost,
            'x-auth-user' => $this->apiKey ?? '',
            'Content-Type' => 'application/json; charset=UTF-8',
            'no-cache' => 'true',
            'Cache-Control' => 'no-cache',
        ];
    }
}
