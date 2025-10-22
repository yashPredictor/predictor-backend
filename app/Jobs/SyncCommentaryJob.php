<?php

namespace App\Jobs;

use App\Services\AdminSettingsService;
use App\Services\CommentarySyncLogger;
use App\Support\Logging\ApiLogging;
use App\Support\Queue\Middleware\RespectPauseWindow;
use Google\Cloud\Firestore\DocumentSnapshot;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\Pool;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Throwable;

class SyncCommentaryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, ApiLogging;

    public const CRON_KEY = 'commentary';
    private const MATCHES_COLLECTION = 'matches';
    private const COMMENTARY_COLLECTION = 'commentary';

    /** @var string[] */
    private array $liveStates = [
        "live",
        "current",
        "progress",
        "inprogress",
        "in progress",
        "inning break",
        "rain",
        "lunch"
    ];

    public int $timeout = 600;
    public int $tries = 5;

    private ?FirestoreClient $firestore = null;
    private ?string $apiKey = null;
    private string $apiHost;
    private string $baseUrl;
    private CommentarySyncLogger $logger;
    private array $firestoreSettings = [];
    private array $cricbuzzSettings = [];
    /** @var string[] */
    private array $targetMatchIds = [];

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

        $this->apiHost = config('services.cricbuzz.host', '139.59.8.120:8987');
        $this->baseUrl = sprintf('http://%s/mcenter/v1/', $this->apiHost);
        $this->logger = new CommentarySyncLogger($this->runId);
        $this->runId = $this->logger->runId;
        $this->initApiLoggingContext($this->runId, self::CRON_KEY);

        $this->log('job_started', 'info', 'SyncCommentary job started', [
            'match_ids' => $this->matchIds,
            'timeout' => $this->timeout,
            'tries' => $this->tries,
        ]);

        $this->firestoreSettings = $settingsService->firestoreSettings();
        $this->cricbuzzSettings = $settingsService->cricbuzzSettings();

        $this->apiHost = $this->cricbuzzSettings['host'] ?? $this->apiHost;
        $this->baseUrl = sprintf('http://%s/mcenter/v1/', $this->apiHost);

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
            $this->log('no_matches', 'warning', 'No live matches found for commentary sync', [
                'requested_ids' => $this->matchIds,
                'api_calls' => $apiSummary,
            ]);
            $this->log('job_completed', 'warning', 'SyncCommentary job finished', [
                'matches_considered' => 0,
                'synced' => 0,
                'skipped' => 0,
                'failures' => [],
                'api_calls' => $apiSummary,
            ]);
            return;
        }

        $this->log('matches_resolved', 'info', 'Resolved match IDs for commentary sync', [
            'match_count' => count($this->targetMatchIds),
            'match_ids' => $this->targetMatchIds,
        ]);

        $headers = $this->getHeaders();
        $synced = 0;
        $skipped = 0;
        $failures = [];

        foreach (array_chunk($this->targetMatchIds, 5) as $chunk) {
            $chunk = array_values($chunk);
            $responses = [];
            $callIds = [];

            try {
                $responses = Http::pool(function (Pool $pool) use ($chunk, $headers, &$callIds) {
                    $requests = [];
                    foreach ($chunk as $matchId) {
                        $url = $this->baseUrl . $matchId . '/comm';
                        $callIds[$matchId] = $this->recordApiCall($url, 'GET', 'commentary_' . $matchId);
                        $requests[] = $pool
                            ->withHeaders($headers)
                            ->get($url);
                    }

                    return $requests;
                });
            } catch (Throwable $e) {
                foreach ($callIds as $callId) {
                    $this->finalizeApiCall($callId, null, $e);
                }
                foreach ($chunk as $matchId) {
                    $failures[] = $matchId;
                    $this->log('commentary_fetch_failed', 'error', 'Failed to fetch commentary batch', $this->exceptionContext($e, [
                        'match_id' => $matchId,
                    ]));
                }
                continue;
            }

            foreach ($chunk as $index => $matchId) {
                $callId = $callIds[$matchId] ?? null;
                $response = $responses[$index] ?? null;
                if ($response === null) {
                    if ($callId !== null) {
                        $this->finalizeApiCall($callId, null, new \RuntimeException('missing_response'));
                    }
                    $failures[] = $matchId;
                    $this->log('commentary_fetch_missing', 'error', 'No response received for commentary request', [
                        'match_id' => $matchId,
                    ]);
                    continue;
                }

                if ($callId !== null) {
                    $this->finalizeApiCall($callId, $response);
                }

                if (!$response->successful()) {
                    $failures[] = $matchId;
                    $this->log('commentary_fetch_error', 'error', 'Cricbuzz API returned error while fetching commentary', $this->responseContext($response, [
                        'match_id' => $matchId,
                    ]));
                    continue;
                }

                $commentaryData = $response->json();
                if (!is_array($commentaryData) || empty($commentaryData)) {
                    $skipped++;
                    $this->log('commentary_fetch_empty', 'warning', 'Commentary API returned empty payload', [
                        'match_id' => $matchId,
                    ]);
                    continue;
                }

                try {
                    $this->persistCommentary($matchId, $commentaryData);
                    $synced++;
                    $this->log('commentary_synced', 'success', 'Stored commentary in Firestore', [
                        'match_id' => $matchId,
                    ]);
                } catch (Throwable $e) {
                    $failures[] = $matchId;
                    $this->log('commentary_persist_failed', 'error', 'Failed to persist commentary to Firestore', $this->exceptionContext($e, [
                        'match_id' => $matchId,
                    ]));
                }
            }
        }

        $apiSummary = $this->getApiCallBreakdown();

        $this->log('job_completed', empty($failures) ? 'success' : 'warning', 'SyncCommentary job finished', [
            'matches_considered' => count($this->targetMatchIds),
            'synced' => $synced,
            'skipped' => $skipped,
            'failures' => array_values(array_unique($failures)),
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
            return array_values(array_unique(array_map(static fn($id) => (string) $id, $this->matchIds)));
        }

        if (!$this->firestore) {
            return [];
        }

        $this->log('match_ids_discovery', 'info', 'Discovering live match IDs from Firestore');

        try {
            $query = $this->firestore
                ->collection(self::MATCHES_COLLECTION)
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

        return array_values(array_unique($ids));
    }

    private function persistCommentary(string $matchId, array $commentaryData): void
    {
        $now = now();
        $timestamp = $now->valueOf();
        $serverTime = $now->toIso8601String();

        $existingData = [];
        try {
            $snapshot = $this->firestore
                ->collection(self::COMMENTARY_COLLECTION)
                ->document($matchId)
                ->snapshot();

            if ($snapshot->exists()) {
                $existingData = $snapshot->data() ?? [];
            }
        } catch (Throwable $e) {
            $this->log('commentary_snapshot_failed', 'warning', 'Failed to read existing commentary snapshot', $this->exceptionContext($e, [
                'match_id' => $matchId,
            ]));
        }

        $existingWrapper = data_get($existingData, 'comwrapper', []);
        $latestWrapper = $commentaryData['comwrapper'] ?? [];

        if (!is_array($existingWrapper)) {
            $existingWrapper = [];
        }
        if (!is_array($latestWrapper)) {
            $latestWrapper = [];
        }

        $commentaryData['comwrapper'] = $this->mergeCommentaryEntries($existingWrapper, $latestWrapper);
        $commentaryData['updatedAt'] = $timestamp;
        $commentaryData['serverTime'] = $serverTime;

        $this->firestore
            ->collection(self::COMMENTARY_COLLECTION)
            ->document($matchId)
            ->set($commentaryData, ['merge' => true]);
    }

    /**
     * @return array<string, string>
     */
    private function getHeaders(): array
    {
        return [
            'x-rapidapi-host' => $this->apiHost,
            'x-auth-user' => $this->apiKey,
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

    /**
     * @param array<int, mixed> $existing
     * @param array<int, mixed> $latest
     * @return array<int, mixed>
     */
    private function mergeCommentaryEntries(array $existing, array $latest): array
    {
        if (empty($existing)) {
            return array_values($latest);
        }

        $seen = [];
        $merged = [];

        foreach ($existing as $entry) {
            $merged[] = $entry;
            $key = $this->commentaryEntryKey($entry);
            if ($key !== null) {
                $seen[$key] = true;
            }
        }

        foreach ($latest as $entry) {
            $key = $this->commentaryEntryKey($entry);
            if ($key !== null && isset($seen[$key])) {
                continue;
            }

            $merged[] = $entry;
            if ($key !== null) {
                $seen[$key] = true;
            }
        }

        return $merged;
    }

    /**
     * @param array<mixed> $entry
     */
    private function commentaryEntryKey(array $entry): ?string
    {
        if (!isset($entry['commentary']) || !is_array($entry['commentary'])) {
            return null;
        }

        $commentary = $entry['commentary'];
        $timestamp = $commentary['timestamp'] ?? null;
        $ball = $commentary['ballnbr'] ?? null;

        if ($timestamp !== null) {
            return 'ts:' . (string) $timestamp . '|ball:' . ($ball !== null ? (string) $ball : 'null');
        }

        if ($ball !== null) {
            return 'ball:' . (string) $ball;
        }

        if (!empty($commentary['commtxt'])) {
            return 'text:' . md5((string) $commentary['commtxt']);
        }

        return null;
    }
}
