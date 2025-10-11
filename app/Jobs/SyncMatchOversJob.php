<?php

namespace App\Jobs;

use Throwable;
use Illuminate\Bus\Queueable;
use Illuminate\Http\Client\Pool;
use App\Support\Logging\ApiLogging;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use App\Services\AdminSettingsService;
use App\Services\MatchOversSyncLogger;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Support\Queue\Middleware\RespectPauseWindow;

class SyncMatchOversJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, ApiLogging;

    private const CRON_KEY = 'match-overs';
    public int $timeout = 600;
    public int $tries = 5;

    private ?FirestoreClient $firestore = null;
    private ?string $apiKey = null;
    private string $apiHost;
    private string $baseUrl;

    private MatchOversSyncLogger $logger;
    private array $firestoreSettings = [];
    private array $cricbuzzSettings = [];

    /** @var string[] */
    private array $liveStates = [
        'live',
        'inprogress',
        'in progress',
    ];

    /** @var string[] */
    private array $targetMatchIds;

    /**
     * @param string[] $matchIds
     */
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
        $this->apiHost = config('services.cricbuzz.host', 'cricbuzz-cricket2.p.rapidapi.com');
        $this->baseUrl = sprintf('https://%s/mcenter/v1/', $this->apiHost);
        $this->logger = new MatchOversSyncLogger($this->runId);
        $this->runId = $this->logger->runId;

        $this->log('job_started', 'info', 'SyncMatchOvers job started', [
            'match_ids' => $this->matchIds,
            'timeout' => $this->timeout,
            'tries' => $this->tries,
        ]);

        $settingsService = app(AdminSettingsService::class);

        if (!$settingsService->isCronEnabled(self::CRON_KEY)) {
            $this->log('job_disabled', 'warning', 'Match overs job paused via emergency controls.');
            return;
        }

        $this->firestoreSettings = $settingsService->firestoreSettings();
        $this->cricbuzzSettings = $settingsService->cricbuzzSettings();

        $this->apiHost = $this->cricbuzzSettings['host'] ?? $this->apiHost;
        $this->baseUrl = sprintf('https://%s/mcenter/v1/', $this->apiHost);

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
            $this->log('no_matches', 'warning', 'No live matches found for overs sync', [
                'requested_ids' => $this->matchIds,
                'api_calls' => $apiSummary,
            ]);
            $this->log('job_completed', 'warning', 'SyncMatchOvers job finished', [
                'matches_considered' => 0,
                'synced' => 0,
                'failures' => [],
                'api_failures' => [],
                'requested_ids' => $this->matchIds,
                'api_calls' => $apiSummary,
            ]);
            return;
        }

        $this->log('matches_resolved', 'info', 'Resolved live matches for overs sync', [
            'match_count' => count($this->targetMatchIds),
            'match_ids' => $this->targetMatchIds,
        ]);

        $headers = $this->getHeaders();
        $bulk = $this->firestore->bulkWriter([
            'maxBatchSize' => 100,
            'initialOpsPerSecond' => 20,
            'maxOpsPerSecond' => 60,
        ]);

        $synced = 0;
        $failures = [];
        $api_failures = [];

        foreach (array_chunk($this->targetMatchIds, 5) as $chunk) {
            try {
                $responses = Http::pool(function (Pool $pool) use ($chunk, $headers) {
                    $requests = [];
                    foreach ($chunk as $matchId) {
                        $url = $this->baseUrl . $matchId . '/overs';
                        $this->recordApiCall($url, 'GET', 'match_overs');
                        $requests[] = $pool->withHeaders($headers)->get($url);
                    }

                    return $requests;
                });
            } catch (Throwable $e) {
                foreach ($chunk as $matchId) {
                    $failures[] = $matchId;
                    $this->log('overs_fetch_failed', 'error', 'Failed to fetch overs batch', $this->exceptionContext($e, [
                        'match_id' => $matchId,
                        'url' => $this->baseUrl . $matchId . '/overs',
                    ]));
                }
                continue;
            }

            foreach ($chunk as $index => $matchId) {
                $response = $responses[$index] ?? null;
                if ($response === null) {
                    $failures[] = $matchId;
                    $this->log('overs_fetch_missing', 'error', 'No response received for overs request', [
                        'match_id' => $matchId,
                        'url' => $this->baseUrl . $matchId . '/overs',
                    ]);
                    continue;
                }

                if (!$response->successful()) {
                    $failures[] = $matchId;
                    $this->log('overs_fetch_error', 'error', 'Cricbuzz API returned error while fetching overs', $this->responseContext($response, [
                        'match_id' => $matchId,
                        'url' => $this->baseUrl . $matchId . '/overs',
                    ]));
                    continue;
                }

                $oversData = $response->json();
                if (!is_array($oversData)) {
                    $api_failures[] = $matchId;
                    $this->log('overs_fetch_invalid', 'info', 'Match overs API returned null payload', $this->responseContext($response, [
                        'match_id' => $matchId,
                        'url' => $this->baseUrl . $matchId . '/overs',
                        'payload' => $response->body(),
                    ]));
                    continue;
                }

                if (!$this->shouldPersistOvers($oversData)) {
                    $this->log('overs_skipped', 'info', 'Overs payload did not contain expected keys', [
                        'match_id' => $matchId,
                        'present_keys' => array_keys($oversData),
                        'expected_keys' => ['miniscore', 'comms', 'commsBkp', 'comwrapper'],
                        'payload' => $oversData,
                    ]);
                    continue;
                }

                try {
                    $docRef = $this->firestore->collection('matchOvers')->document($matchId);
                    $apiUpdatedAt = (int) ($oversData['responselastupdated']
                        ?? ($oversData['miniscore']['responselastupdated'] ?? 0));

                    $existingSnapshot = null;
                    $existingData = null;

                    try {
                        $existingSnapshot = $docRef->snapshot();
                    } catch (Throwable $snapshotError) {
                        $this->log('overs_snapshot_failed', 'warning', 'Failed to read existing match overs snapshot', $this->exceptionContext($snapshotError, [
                            'match_id' => $matchId,
                        ]));
                    }

                    if ($existingSnapshot?->exists()) {
                        $existingData = $existingSnapshot->data();
                    }

                    if (is_array($existingData) && isset($existingData['oversData']) && is_array($existingData['oversData'])) {
                        $preserved = $this->preserveRunProgress($oversData, $existingData['oversData']);

                        if ($preserved !== $oversData) {
                            $this->log('overs_runs_preserved', 'info', 'Retained higher run totals from existing overs snapshot', [
                                'match_id' => $matchId,
                            ]);
                            $oversData = $preserved;
                        }
                    }

                    $payload = [
                        'oversData' => $oversData,
                        'apiUpdatedAt' => $apiUpdatedAt,
                        'serverTime' => now()->toIso8601String(),
                        'lastFetched' => now()->valueOf(),
                    ];

                    $bulk->set($docRef, $payload, ['merge' => true]);

                    $synced++;
                    $this->log('overs_synced', 'success', 'Stored overs in Firestore', [
                        'match_id' => $matchId,
                        'overs_data' => $oversData,
                        'apiUpdatedAt' => $apiUpdatedAt,
                    ]);
                } catch (Throwable $e) {
                    $failures[] = $matchId;
                    $this->log('overs_persist_failed', 'error', 'Failed to persist overs to Firestore', $this->exceptionContext($e, [
                        'match_id' => $matchId,
                        'overs_data' => $oversData,
                    ]));
                }
            }
        }

        try {
            $bulk->flush(true);
            $bulk->close();
        } catch (Throwable $e) {
            $this->log('overs_bulk_flush_failed', 'error', 'Failed to flush Firestore bulk writer', [
                'exception' => $e->getMessage(),
            ]);
        }

        $apiSummary = $this->getApiCallBreakdown();

        $this->log('job_completed', empty($failures) ? 'success' : 'warning', 'SyncMatchOvers job finished', [
            'matches_considered' => count($this->targetMatchIds),
            'synced' => $synced,
            'failures' => array_values(array_unique($failures)),
            'api_failures' => array_values(array_unique($api_failures)),
            'requested_ids' => $this->matchIds,
            'api_calls' => $apiSummary,
        ]);
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
                'Firestore project id missing. Set FIRESTORE_PROJECT_ID in .env or ensure your service account JSON has project_id.'
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
        if (!empty($this->matchIds)) {
            $resolved = array_values(array_unique(array_map(static fn($id) => (string) $id, $this->matchIds)));
            $this->log('match_ids_provided', 'info', 'Using provided match IDs for overs sync', [
                'match_count' => count($resolved),
                'match_ids' => $resolved,
            ]);
            return $resolved;
        }

        if (!$this->firestore) {
            return [];
        }

        $this->log('match_ids_discovery', 'info', 'Discovering live match IDs from Firestore');

        try {
            $query = $this->firestore
                ->collection('matches')
                ->where('matchInfo.state_lowercase', 'in', array_slice($this->liveStates, 0, 10));

            $documents = $query->documents();
        } catch (Throwable $e) {
            $this->log('match_id_query_failed', 'error', 'Failed to query live matches from Firestore', $this->exceptionContext($e));
            return [];
        }

        $ids = [];
        foreach ($documents as $snapshot) {
            if (!$snapshot->exists()) {
                continue;
            }

            $ids[] = $snapshot->id();
        }
        $discovered = array_values(array_unique($ids));

        $this->log('match_ids_discovered', 'info', 'Discovered match IDs from Firestore', [
            'match_ids' => $discovered,
            'match_count' => count($discovered),
        ]);

        return $discovered;
    }

    /**
     * @param array<string, mixed> $oversData
     */
    private function shouldPersistOvers(array $oversData): bool
    {
        $requiredKeys = ['miniscore', 'comms', 'commsBkp', 'comwrapper'];

        foreach ($requiredKeys as $key) {
            if (array_key_exists($key, $oversData)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    private function getHeaders(): array
    {
        return [
            'x-rapidapi-host' => $this->apiHost,
            'x-rapidapi-key' => $this->apiKey,
            'Content-Type' => 'application/json; charset=UTF-8',
        ];
    }

    private function log(string $action, ?string $status, string $message, array $context = []): void
    {
        if (!isset($this->logger)) {
            return;
        }

        $this->logger->log($action, $status, $message, $context);
    }

    
    private function preserveRunProgress(array $latest, array $existing): array
    {
        $maxStep = 15;

        foreach ($latest as $key => $value) {
            if ($key === 'runs' && isset($existing[$key]) && is_numeric($existing[$key]) && is_numeric($value)) {
                $old = (int) $existing[$key];
                $new = (int) $value;

                if ($new >= $old) {
                    $latest[$key] = ($new - $old) <= $maxStep ? $new : $old;
                } else {
                    $latest[$key] = ($old - $new) > $maxStep ? $new : $old;
                }
                continue;
            }

            if (is_array($value) && isset($existing[$key]) && is_array($existing[$key])) {
                $latest[$key] = $this->preserveRunProgress($value, $existing[$key]);
            }
        }

        return $latest;
    }
}
