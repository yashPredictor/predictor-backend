<?php

namespace App\Jobs;

use App\Services\ScorecardSyncLogger;
use App\Support\Logging\ApiLogging;
use App\Support\Queue\Middleware\RespectPauseWindow;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Throwable;

class SyncScorecardJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, ApiLogging;

    private const SCORECARD_STALE_AFTER_MS = 60_000; // 1 minute
    private const SQUAD_STALE_AFTER_MS     = 600_000; // 10 minutes

    public int $timeout = 600;
    public int $tries   = 5;

    private ?FirestoreClient $firestore = null;
    private ?string $apiKey             = null;
    private string $apiHost;
    private string $baseUrl;
    private string $matchesCollection;
    private string $scorecardsCollection;
    private string $squadsCollection;

    private ScorecardSyncLogger $logger;

    /** @var string[] */
    private array $liveStates = [
        "live",
        "Live",
        "current",
        "Current",
        "progress",
        "Progress",
        "inprogress",
        "Inprogress",
        "in progress",
        "In progress",
        "In Progress",
    ];

    /** @var string[] */
    private array $targetMatchIds = [];

    private int $scorecardsSynced  = 0;
    private int $scorecardsSkipped = 0;
    private int $scorecardsFailed  = 0;
    private int $squadsSynced      = 0;
    private int $squadsSkipped     = 0;
    private int $squadsFailed      = 0;

    public function __construct(
        private readonly array $matchIds = [],
        private ?string $runId = null,
    ) {}

    /**
     * @return string[]
     */
    public function middleware(): array
    {
        return [RespectPauseWindow::class];
    }

    public function handle(): void
    {
        $this->apiHost             = config('services.cricbuzz.host', 'cricbuzz-cricket2.p.rapidapi.com');
        $this->baseUrl             = sprintf('https://%s/mcenter/v1/', $this->apiHost);
        $this->matchesCollection   = config('services.firestore.matches_collection', 'matches');
        $this->scorecardsCollection = config('services.firestore.scorecards_collection', 'scorecards');
        $this->squadsCollection    = config('services.firestore.squads_collection', 'squads');

        $this->logger = new ScorecardSyncLogger($this->runId);
        $this->runId  = $this->logger->runId;

        $this->log('job_started', 'info', 'SyncScorecard job started', [
            'requested_match_ids' => $this->matchIds,
            'timeout'             => $this->timeout,
            'tries'               => $this->tries,
        ]);

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
            $this->log('no_matches', 'warning', 'No live matches found for scorecard sync', [
                'requested_ids' => $this->matchIds,
                'api_calls'     => $apiSummary,
            ]);
            $this->finalize('warning');
            return;
        }

        $this->log('matches_resolved', 'info', 'Resolved match IDs for scorecard sync', [
            'match_count' => count($this->targetMatchIds),
            'match_ids'   => $this->targetMatchIds,
        ]);

        foreach ($this->targetMatchIds as $matchId) {
            $this->processMatch($matchId);
        }

        $status = ($this->scorecardsFailed > 0 || $this->squadsFailed > 0) ? 'warning' : 'success';
        $this->finalize($status);
    }

    private function processMatch(string $matchId): void
    {
        try {
            $scorecardSynced = $this->syncScorecard($matchId);
            if ($scorecardSynced) {
                $this->scorecardsSynced++;
            } else {
                $this->scorecardsSkipped++;
            }
        } catch (Throwable $e) {
            $this->scorecardsFailed++;
            $this->log('scorecard_sync_failed', 'error', 'Failed to sync scorecard', $this->exceptionContext($e, [
                'match_id' => $matchId,
            ]));
        }

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

    private function syncScorecard(string $matchId): bool
    {
        $docRef = $this->firestore
            ->collection($this->scorecardsCollection)
            ->document($matchId);

        $shouldRefresh = true;
        $snapshot = null;

        try {
            $snapshot = $docRef->snapshot();
            if ($snapshot->exists()) {
                $existingData = $snapshot->data();
                $lastFetched  = (int) ($existingData['lastFetched'] ?? 0);
                $ageMs        = now()->valueOf() - $lastFetched;

                if ($lastFetched > 0 && $ageMs < self::SCORECARD_STALE_AFTER_MS) {
                    $shouldRefresh = false;
                }
            }
        } catch (Throwable $e) {
            $this->log('scorecard_snapshot_failed', 'warning', 'Failed to read existing scorecard snapshot', $this->exceptionContext($e, [
                'match_id' => $matchId,
            ]));
        }

        if (!$shouldRefresh) {
            $this->log('scorecard_cached', 'info', 'Scorecard considered fresh; skipping fetch', [
                'match_id' => $matchId,
            ]);
            return false;
        }

        $url = $this->baseUrl . $matchId . '/scard';
        $response = $this->performApiRequest($url, 'scorecard');

        if ($response === null) {
            throw new \RuntimeException('No response received from scorecard API');
        }

        if (!$response->successful()) {
            $this->log('scorecard_fetch_error', 'error', 'Scorecard API returned an error response', $this->responseContext($response, [
                'match_id' => $matchId,
                'url'      => $url,
            ]));
            throw new \RuntimeException('Scorecard API returned error code ' . $response->status());
        }

        $payload = $response->json();
        if (!is_array($payload) || empty($payload)) {
            $this->log('scorecard_fetch_invalid', 'warning', 'Scorecard API returned empty payload', [
                'match_id' => $matchId,
                'url'      => $url,
            ]);
            return false;
        }

        $docRef->set($payload, ['merge' => true]);

        $this->log('scorecard_synced', 'success', 'Stored scorecard in Firestore', [
            'match_id' => $matchId,
        ]);

        return true;
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
                'url'      => $url,
            ]));
            throw new \RuntimeException('Squads API returned error code ' . $response->status());
        }

        $payload = $response->json();
        if (!is_array($payload) || empty($payload)) {
            $this->log('squads_fetch_invalid', 'warning', 'Squads API returned empty payload', [
                'match_id' => $matchId,
                'url'      => $url,
            ]);
            return false;
        }

        $documentPayload = array_merge($payload, [
            'lastFetched' => now()->valueOf(),
            'serverTime'  => now()->toIso8601String(),
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
                'x-rapidapi-key'  => $this->apiKey,
                'Content-Type'    => 'application/json; charset=UTF-8',
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
        $keyPath   = config('services.firestore.sa_json');
        $projectId = config('services.firestore.project_id');

        if (!$projectId && $keyPath && is_file($keyPath)) {
            $json      = json_decode(file_get_contents($keyPath), true);
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
    private function resolveMatchIds(): array
    {
        if (!empty($this->matchIds)) {
            $resolved = array_values(array_unique(array_map(static fn($id) => (string) $id, $this->matchIds)));
            $this->log('match_ids_provided', 'info', 'Using provided match IDs for scorecard sync', [
                'match_count' => count($resolved),
                'match_ids'   => $resolved,
            ]);

            return $resolved;
        }

        if (!$this->firestore) {
            return [];
        }

        $this->log('match_ids_discovery', 'info', 'Discovering live match IDs from Firestore');

        try {
            $query = $this->firestore
                ->collection($this->matchesCollection)
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

            $ids[] = (string) $snapshot->id();
        }

        $resolved = array_values(array_unique($ids));

        $this->log('match_ids_discovered', 'info', 'Discovered match IDs from Firestore', [
            'match_ids'   => $resolved,
            'match_count' => count($resolved),
        ]);

        return $resolved;
    }

    private function finalize(string $status): void
    {
        $apiSummary = $this->getApiCallBreakdown();

        $this->log('job_completed', $status, 'SyncScorecard job finished', [
            'matches_considered' => count($this->targetMatchIds),
            'scorecards_synced'  => $this->scorecardsSynced,
            'scorecards_skipped' => $this->scorecardsSkipped,
            'scorecards_failed'  => $this->scorecardsFailed,
            'squads_synced'      => $this->squadsSynced,
            'squads_skipped'     => $this->squadsSkipped,
            'squads_failed'      => $this->squadsFailed,
            'requested_ids'      => $this->matchIds,
            'api_calls'          => $apiSummary,
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
