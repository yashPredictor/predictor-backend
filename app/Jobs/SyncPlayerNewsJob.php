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

class SyncPlayerNewsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const PLAYERS_COLLECTION = 'players';
    private const PLAYER_NEWS_COLLECTION = 'playerNews';

    public int $timeout = 600;
    public int $tries = 3;

    private ?FirestoreClient $firestore = null;
    private ?string $apiKey = null;
    private string $apiHost;
    private string $baseUrl;

    public function __construct(
        private readonly array $playerIds = [],
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
        $this->baseUrl = sprintf('http://%s/news/v1/player/', $this->apiHost);

        try {
            $this->firestore = $this->initializeFirestoreClient();
        } catch (Throwable $e) {
            report($e);
            return;
        }

        $playerIds = $this->resolvePlayerIds();
        if (empty($playerIds) || $this->firestore === null) {
            return;
        }

        $headers = $this->buildHeaders();

        foreach ($playerIds as $playerId) {
            $payload = $this->fetchPlayerNews($playerId, $headers);
            if ($payload === null) {
                continue;
            }

            $this->storePlayerNews($playerId, $payload);
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
    private function resolvePlayerIds(): array
    {
        $explicit = array_values(array_filter(array_map(
            static fn($id) => trim((string) $id),
            $this->playerIds
        )));

        if (!empty($explicit)) {
            return array_values(array_unique($explicit));
        }

        if ($this->firestore === null) {
            return [];
        }

        try {
            $documents = $this->firestore
                ->collection(self::PLAYERS_COLLECTION)
                ->documents();
        } catch (Throwable $e) {
            report($e);
            return [];
        }

        $playerIds = [];
        foreach ($documents as $snapshot) {
            $playerId = $this->extractPlayerId($snapshot);
            if ($playerId !== null) {
                $playerIds[] = $playerId;
            }
        }

        return array_values(array_unique($playerIds));
    }

    private function extractPlayerId(DocumentSnapshot $snapshot): ?string
    {
        if (!$snapshot->exists()) {
            return null;
        }

        $data = $snapshot->data();
        $playerId = (string) ($data['id'] ?? $snapshot->id());

        return $playerId === '' ? null : $playerId;
    }

    /**
     * @param array<string, string> $headers
     */
    private function fetchPlayerNews(string $playerId, array $headers): ?array
    {
        $uq = bin2hex(random_bytes(8));
        $url = $this->baseUrl . $playerId . '?uq=' . $uq;

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

    private function storePlayerNews(string $playerId, array $payload): void
    {
        if ($this->firestore === null) {
            return;
        }

        $documentPayload = $payload + [
            'playerId' => $playerId,
            'lastFetched' => now()->valueOf(),
        ];

        try {
            $this->firestore
                ->collection(self::PLAYER_NEWS_COLLECTION)
                ->document($playerId)
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

