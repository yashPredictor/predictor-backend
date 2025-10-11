<?php

namespace App\Jobs;

use App\Services\AdminSettingsService;
use App\Services\SquadSyncLogger;
use App\Support\Logging\ApiLogging;
use App\Support\Queue\Middleware\RespectPauseWindow;
use Google\Cloud\Firestore\DocumentSnapshot;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncSquadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, ApiLogging;

    private const SQUAD_STALE_AFTER_MS = 600_000;
    private const ALLOWED_STATES = ['preview', 'upcoming'];
    public const CRON_KEY = 'squads';
    private const MATCHES_COLLECTION = 'matches';
    private const SQUADS_COLLECTION = 'squads';
    public int $timeout = 600;
    public int $tries = 5;
    private ?FirestoreClient $firestore = null;
    private ?string $apiKey = null;
    private string $apiHost;
    private string $baseUrl;
    private string $matchesCollection;
    private string $squadsCollection;
    private SquadSyncLogger $logger;
    private array $firestoreSettings = [];
    private array $cricbuzzSettings = [];
    private array $targetMatchIds = [];
    private int $squadsSynced = 0;
    private int $squadsSkipped = 0;
    private int $squadsFailed = 0;

    public function __construct(
        private readonly array $matchIds = [],
        private ?string $runId = null,
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
        $settingsService = app(AdminSettingsService::class);

        if (!$settingsService->isCronEnabled(self::CRON_KEY)) {
            return;
        }

        $this->apiHost = config('services.cricbuzz.host', 'cricbuzz-cricket2.p.rapidapi.com');
        $this->baseUrl = sprintf('https://%s/mcenter/v1/', $this->apiHost);
        $this->matchesCollection = self::MATCHES_COLLECTION;
        $this->squadsCollection = self::SQUADS_COLLECTION;

        $this->logger = new SquadSyncLogger($this->runId);
        $this->runId = $this->logger->runId;

        $this->log('job_started', 'info', 'SyncSquad job started', [
            'requested_match_ids' => $this->matchIds,
            'timeout' => $this->timeout,
            'tries' => $this->tries,
        ]);

        $this->firestoreSettings = $settingsService->firestoreSettings();
        $this->cricbuzzSettings = $settingsService->cricbuzzSettings();

        $this->apiHost = $this->cricbuzzSettings['host'] ?? $this->apiHost;
        $this->baseUrl = sprintf('https://%s/mcenter/v1/', $this->apiHost);
        $this->matchesCollection = self::MATCHES_COLLECTION;
        $this->squadsCollection = self::SQUADS_COLLECTION;

        try {
            $this->firestore = $this->initializeClients();
            $this->log('initialize_clients', 'success', 'Firestore client initialised');
        } catch (Throwable $e) {
            $this->log('initialize_clients', 'error', 'Failed to initialise Firestore client', $this->exceptionContext($e));

            throw $e;
        }

        $this->targetMatchIds = $this->resolveMatchIds();
        
        if (empty($this->targetMatchIds)) {
            $apiSummary = $this->getApiCallBreakdown();
            $this->log('no_matches', 'warning', 'No upcoming matches found for squad sync', [
                'requested_ids' => $this->matchIds,
                'api_calls' => $apiSummary,
            ]);
            $this->finalize('warning');
            return;
        }

        $this->log('matches_resolved', 'info', 'Resolved match IDs for squad sync', [
            'match_count' => count($this->targetMatchIds),
            'match_ids' => $this->targetMatchIds,
        ]);

        foreach ($this->targetMatchIds as $matchId) {
            $this->processMatch($matchId);
        }

        $status = $this->squadsFailed > 0 ? 'warning' : 'success';
        $this->finalize($status);
    }

    private function processMatch(string $matchId): void
    {
        try {
            $squadsSynced = $this->syncSquads($matchId);
            if ($squadsSynced) {
                $this->squadsSynced++;
            } else {
                $this->squadsSkipped++;
            }
        } catch (Throwable $e) {
            $this->squadsFailed++;
            $this->log('squads_sync_failed', 'error', 'Failed to sync squads', $this->exceptionContext($e, [
                'match_id' => $matchId,
            ]));
        }
    }

    private function syncSquads(string $matchId): bool
    {
        $docRef = $this->firestore
            ->collection($this->squadsCollection)
            ->document($matchId);

        $snapshot = null;
        $shouldRefresh = true;

        try {
            $snapshot = $docRef->snapshot();
            if ($snapshot->exists()) {
                $existing = $snapshot->data();
                $lastFetched = (int) ($existing['lastFetched'] ?? 0);
                $hasPlayers = !empty(data_get($existing, 'squads.team1.players'))
                    && !empty(data_get($existing, 'squads.team2.players'));

                if ($hasPlayers) {
                    $ageMs = now()->valueOf() - $lastFetched;
                    if ($lastFetched > 0 && $ageMs < self::SQUAD_STALE_AFTER_MS) {
                        $shouldRefresh = false;
                    }
                }
            }
        } catch (Throwable $e) {
            $this->log('squads_snapshot_failed', 'warning', 'Failed to read existing squad snapshot', $this->exceptionContext($e, [
                'match_id' => $matchId,
            ]));
        }

        if (!$shouldRefresh) {
            $this->log('squads_cached', 'info', 'Squads considered fresh; skipping fetch', [
                'match_id' => $matchId,
            ]);
            return false;
        }

        $url = $this->baseUrl . $matchId . '/teams';
        $response = $this->performApiRequest($url, 'squads');

        if ($response === null) {
            throw new \RuntimeException('No response received from squads API');
        }

        if (!$response->successful()) {
            $this->log('squads_fetch_error', 'error', 'Squads API returned an error response', $this->responseContext($response, [
                'match_id' => $matchId,
                'url' => $url,
            ]));
            throw new \RuntimeException('Squads API returned error code ' . $response->status());
        }

        $payload = $response->json();
        if (!is_array($payload) || empty($payload)) {
            $this->log('squads_fetch_invalid', 'warning', 'Squads API returned empty payload', [
                'match_id' => $matchId,
                'url' => $url,
            ]);
            return false;
        }

        $documentPayload = array_merge($payload, [
            'lastFetched' => now()->valueOf(),
            'serverTime' => now()->toIso8601String(),
        ]);

        $docRef->set($documentPayload, ['merge' => true]);

        $this->log('squads_synced', 'success', 'Stored squads in Firestore', [
            'match_id' => $matchId,
        ]);

        return true;
    }

    private function performApiRequest(string $url, string $tag)
    {
        try {
            $this->recordApiCall($url, 'GET', $tag);

            return Http::withHeaders([
                'x-rapidapi-host' => $this->apiHost,
                'x-rapidapi-key' => $this->apiKey,
                'Content-Type' => 'application/json; charset=UTF-8',
            ])->get($url);
        } catch (Throwable $e) {
            $this->log('api_request_failed', 'error', 'API request threw an exception', $this->exceptionContext($e, [
                'url' => $url,
                'tag' => $tag,
            ]));

            return null;
        }
    }

    private function initializeClients(): FirestoreClient
    {
        $keyPath = $this->firestoreSettings['sa_json'] ?? config('services.firestore.sa_json');
        $projectId = $this->firestoreSettings['project_id'] ?? config('services.firestore.project_id');

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

        $this->apiKey = $this->cricbuzzSettings['key'] ?? config('services.cricbuzz.key');
        if (!$this->apiKey) {
            throw new \RuntimeException('Cricbuzz API key is not configured.');
        }

        return new FirestoreClient($options);
    }

    /**
     * @return string[]
     */
    private function resolveMatchIds(): array
    {
        $now = now();
        $windowStart = $now->copy()->valueOf();
        $windowEnd = $now->copy()->addDay()->valueOf();

        if (!empty($this->matchIds)) {
            return $this->filterMatchIdsByWindow(
                array_values(array_unique(array_map(static fn($id) => (string) $id, $this->matchIds))),
                $windowStart,
                $windowEnd
            );
        }

        if (!$this->firestore) {
            return [];
        }

        $this->log('match_ids_discovery', 'info', 'Discovering upcoming match IDs from Firestore', [
            'window_start' => $windowStart,
            'window_end' => $windowEnd,
        ]);

        try {
            $query = $this->firestore
                ->collection($this->matchesCollection)
                ->where('matchInfo.startdate', '>=', $windowStart)
                ->where('matchInfo.startdate', '<=', $windowEnd);

            $documents = $query->documents();
        } catch (Throwable $e) {
            $this->log('match_id_query_failed', 'error', 'Failed to query upcoming matches from Firestore', $this->exceptionContext($e));
            return [];
        }

        $ids = [];
        foreach ($documents as $snapshot) {
            if (!$snapshot->exists()) {
                continue;
            }

            $data = $snapshot->data();
            if (!$this->isStateAllowed($data)) {
                continue;
            }

            if (!$this->isStartTimeWithinWindow($data, $windowStart, $windowEnd)) {
                continue;
            }

            $ids[] = (int) $snapshot->id();
        }

        return array_values(array_unique($ids));
    }

    private function filterMatchIdsByWindow(array $matchIds, int $windowStart, int $windowEnd): array
    {
        if (!$this->firestore || empty($matchIds)) {
            return [];
        }

        $allowed = [];

        foreach ($matchIds as $matchId) {
            try {
                /** @var DocumentSnapshot $snapshot */
                $snapshot = $this->firestore
                    ->collection($this->matchesCollection)
                    ->document($matchId)
                    ->snapshot();
            } catch (Throwable $e) {
                $this->log('match_state_lookup_failed', 'warning', 'Failed to fetch match document while filtering by state', $this->exceptionContext($e, [
                    'match_id' => $matchId,
                ]));
                continue;
            }

            if (!$snapshot->exists()) {
                $this->log('match_missing', 'warning', 'Match document not found while filtering by state', [
                    'match_id' => $matchId,
                ]);
                continue;
            }

            $data = $snapshot->data();
            if (!$this->isStateAllowed($data)) {
                $this->log('match_skipped_state', 'info', 'Skipping match for squad sync due to state filter', [
                    'match_id' => $matchId,
                    'state' => strtolower((string) data_get($data, 'matchInfo.state_lowercase', '')),
                ]);
                continue;
            }

            if (!$this->isStartTimeWithinWindow($data, $windowStart, $windowEnd)) {
                $this->log('match_skipped_time', 'info', 'Skipping match for squad sync due to start time window', [
                    'match_id' => $matchId,
                    'start' => data_get($data, 'matchInfo.startdate'),
                    'window_start' => $windowStart,
                    'window_end' => $windowEnd,
                ]);
                continue;
            }

            $allowed[] = $matchId;
        }

        return $allowed;
    }

    private function isStateAllowed(array $data): bool
    {
        $state = strtolower((string) data_get($data, 'matchInfo.state_lowercase', ''));

        return in_array($state, self::ALLOWED_STATES, true);
    }

    private function isStartTimeWithinWindow(array $data, int $windowStart, int $windowEnd): bool
    {
        $startDate = data_get($data, 'matchInfo.startdate');

        if (!is_numeric($startDate)) {
            return false;
        }

        $startDate = (int) $startDate;

        return $startDate >= $windowStart && $startDate <= $windowEnd;
    }

    private function finalize(string $status): void
    {
        $apiSummary = $this->getApiCallBreakdown();

        $this->log('job_completed', $status, 'SyncSquad job finished', [
            'matches_considered' => count($this->targetMatchIds),
            'squads_synced' => $this->squadsSynced,
            'squads_skipped' => $this->squadsSkipped,
            'squads_failed' => $this->squadsFailed,
            'requested_ids' => $this->matchIds,
            'api_calls' => $apiSummary,
        ]);
    }

    private function log(string $action, ?string $status, string $message, array $context = []): void
    {
        if (!isset($this->logger)) {
            return;
        }

        $this->logger->log($action, $status, $message, $context);
    }
}
