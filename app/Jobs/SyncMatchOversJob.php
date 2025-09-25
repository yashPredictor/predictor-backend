<?php

namespace App\Jobs;

use App\Services\MatchOversSyncLogger;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\Pool;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Throwable;

class SyncMatchOversJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;
    public int $tries   = 5;

    private ?FirestoreClient $firestore = null;
    private ?string $apiKey             = null;
    private string $apiHost;
    private string $baseUrl;

    private MatchOversSyncLogger $logger;

    /** @var string[] */
    private array $liveStates = [
        'live',
        'inprogress',
        'stumps',
        'lunch',
        'drinks',
        'innings break',
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
    ) {}

    public function handle(): void
    {
        $this->apiHost = config('services.cricbuzz.host', 'cricbuzz-cricket2.p.rapidapi.com');
        $this->baseUrl = sprintf('https://%s/mcenter/v1/', $this->apiHost);
        $this->logger  = new MatchOversSyncLogger($this->runId);
        $this->runId   = $this->logger->runId;

        $this->log('job_started', 'info', 'SyncMatchOvers job started', [
            'match_ids' => $this->matchIds,
            'timeout'   => $this->timeout,
            'tries'     => $this->tries,
        ]);

        try {
            $this->firestore = $this->initializeClients();
            $this->log('initialize_clients', 'success', 'Firestore client initialised');
        } catch (Throwable $e) {
            $this->log('initialize_clients', 'error', 'Failed to initialise Firestore client', [
                'exception' => $e->getMessage(),
            ]);

            throw $e;
        }

        $this->targetMatchIds = $this->resolveMatchIds();

        if (empty($this->targetMatchIds)) {
            $this->log('no_matches', 'warning', 'No live matches found for overs sync');
            return;
        }

        $this->log('matches_resolved', 'info', 'Resolved live matches for overs sync', [
            'match_count' => count($this->targetMatchIds),
        ]);

        $headers = $this->getHeaders();
        $bulk    = $this->firestore->bulkWriter([
            'maxBatchSize'        => 100,
            'initialOpsPerSecond' => 20,
            'maxOpsPerSecond'     => 60,
        ]);

        $synced   = 0;
        $failures = [];

        foreach (array_chunk($this->targetMatchIds, 5) as $chunk) {
            try {
                $responses = Http::pool(function (Pool $pool) use ($chunk, $headers) {
                    $requests = [];
                    foreach ($chunk as $matchId) {
                        $url          = $this->baseUrl . $matchId . '/overs';
                        $requests[]   = $pool->withHeaders($headers)->get($url);
                    }

                    return $requests;
                });
            } catch (Throwable $e) {
                foreach ($chunk as $matchId) {
                    $failures[] = $matchId;
                    $this->log('overs_fetch_failed', 'error', 'Failed to fetch overs batch', [
                        'match_id'  => $matchId,
                        'exception' => $e->getMessage(),
                    ]);
                }
                continue;
            }

            foreach ($chunk as $index => $matchId) {
                $response = $responses[$index] ?? null;
                if ($response === null) {
                    $failures[] = $matchId;
                    $this->log('overs_fetch_missing', 'error', 'No response received for overs request', [
                        'match_id' => $matchId,
                    ]);
                    continue;
                }

                if (!$response->successful()) {
                    $failures[] = $matchId;
                    $this->log('overs_fetch_error', 'error', 'Cricbuzz API returned error while fetching overs', [
                        'match_id' => $matchId,
                        'status'   => $response->status(),
                        'body'     => $response->body(),
                    ]);
                    continue;
                }

                $oversData = $response->json();
                if (!is_array($oversData)) {
                    $failures[] = $matchId;
                    $this->log('overs_fetch_invalid', 'error', 'Match overs API returned invalid payload', [
                        'match_id' => $matchId,
                    ]);
                    continue;
                }

                if (!$this->shouldPersistOvers($oversData)) {
                    $this->log('overs_skipped', 'info', 'Overs payload did not contain expected keys', [
                        'match_id' => $matchId,
                        'keys'     => array_keys($oversData),
                    ]);
                    continue;
                }

                try {
                    $docRef = $this->firestore->collection('matchOvers')->document($matchId);
                    $bulk->set($docRef, [
                        'oversData'   => $oversData,
                        'lastFetched' => now()->timestamp,
                    ], ['merge' => true]);

                    $synced++;
                    $this->log('overs_synced', 'success', 'Stored overs in Firestore', [
                        'match_id' => $matchId,
                    ]);
                } catch (Throwable $e) {
                    $failures[] = $matchId;
                    $this->log('overs_persist_failed', 'error', 'Failed to persist overs to Firestore', [
                        'match_id'  => $matchId,
                        'exception' => $e->getMessage(),
                    ]);
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

        $this->log('job_completed', empty($failures) ? 'success' : 'warning', 'SyncMatchOvers job finished', [
            'matches_considered' => count($this->targetMatchIds),
            'synced'             => $synced,
            'failures'           => array_values(array_unique($failures)),
        ]);
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
                'Firestore project id missing. Set FIRESTORE_PROJECT_ID in .env or ensure your service account JSON has project_id.'
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
            $resolved = array_values(array_unique(array_map(static fn ($id) => (string) $id, $this->matchIds)));
            $this->log('match_ids_provided', 'info', 'Using provided match IDs for overs sync', [
                'match_count' => count($resolved),
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
            $this->log('match_id_query_failed', 'error', 'Failed to query live matches from Firestore', [
                'exception' => $e->getMessage(),
            ]);
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
            'x-rapidapi-key'  => $this->apiKey,
            'Content-Type'    => 'application/json; charset=UTF-8',
        ];
    }

    private function log(string $action, ?string $status, string $message, array $context = []): void
    {
        if (!isset($this->logger)) {
            return;
        }

        $this->logger->log($action, $status, $message, $context);
    }
}
