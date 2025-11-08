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

class SyncTeamNewsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const TEAMS_LIST_COLLECTION = 'teamsList';
    private const TEAMS_LIST_DOCUMENT = 'all-categories';
    private const TEAM_NEWS_COLLECTION = 'teamNews';

    public int $timeout = 600;
    public int $tries = 3;

    private ?FirestoreClient $firestore = null;
    private ?string $apiKey = null;
    private string $apiHost;
    private string $baseUrl;

    public function __construct(
        private readonly array $teamIds = [],
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
        $this->baseUrl = sprintf('http://%s/news/v1/team/', $this->apiHost);

        try {
            $this->firestore = $this->initializeFirestoreClient();
        } catch (Throwable $e) {
            report($e);
            return;
        }

        $teamIds = $this->resolveTeamIds();
        if (empty($teamIds) || $this->firestore === null) {
            return;
        }

        $headers = $this->buildHeaders();

        foreach ($teamIds as $teamId) {
            $payload = $this->fetchTeamNews($teamId, $headers);
            if ($payload === null) {
                continue;
            }

            $this->storeTeamNews($teamId, $payload);
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
    private function resolveTeamIds(): array
    {
        $explicit = array_values(array_filter(array_map(
            static fn($id) => trim((string) $id),
            $this->teamIds
        )));

        if (!empty($explicit)) {
            return array_values(array_unique($explicit));
        }

        if ($this->firestore === null) {
            return [];
        }

        return $this->fetchTeamIdsFromAllCategories();
    }

    /**
     * @return string[]
     */
    private function fetchTeamIdsFromAllCategories(): array
    {
        if ($this->firestore === null) {
            return [];
        }

        try {
            $snapshot = $this->firestore
                ->collection(self::TEAMS_LIST_COLLECTION)
                ->document(self::TEAMS_LIST_DOCUMENT)
                ->snapshot();
        } catch (Throwable $e) {
            report($e);
            return [];
        }

        if (!$snapshot->exists()) {
            return [];
        }

        $data = $snapshot->data();
        $teams = $data['teams'] ?? [];
        if (!is_array($teams)) {
            return [];
        }

        $teamIds = [];
        foreach ($teams as $team) {
            if (!is_array($team)) {
                continue;
            }

            $teamId = (string) ($team['teamId'] ?? '');
            if ($teamId === '') {
                continue;
            }

            $teamIds[] = $teamId;
        }

        return array_values(array_unique($teamIds));
    }

    /**
     * @param array<string, string> $headers
     */
    private function fetchTeamNews(string $teamId, array $headers): ?array
    {
        $url = $this->baseUrl . $teamId;

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

    private function storeTeamNews(string $teamId, array $payload): void
    {
        if ($this->firestore === null) {
            return;
        }

        $documentPayload = $payload + [
            'teamId' => $teamId,
            'lastFetched' => now()->valueOf(),
        ];

        try {
            $this->firestore
                ->collection(self::TEAM_NEWS_COLLECTION)
                ->document($teamId)
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

