<?php

namespace App\Jobs;

use App\Services\AdminSettingsService;
use App\Services\LiveMatchSyncLogger;
use App\Support\Logging\ApiLogging;
use App\Support\Queue\Middleware\RespectPauseWindow;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Throwable;

class SyncLiveMatchesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, ApiLogging;

    public const CRON_KEY = 'live-matches';

    public int $timeout = 600;

    public int $tries = 5;

    private ?FirestoreClient $firestore = null;

    private ?string $apiKey = null;

    private string $apiHost;

    private LiveMatchSyncLogger $logger;

    private array $firestoreSettings = [];

    private array $cricbuzzSettings = [];

    /** @var string[] */
    private array $candidateMatchIds = [];

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
        $this->logger = new LiveMatchSyncLogger($this->runId);
        $this->runId = $this->logger->runId;
        $this->initApiLoggingContext($this->runId, self::CRON_KEY);

        $this->log('job_started', 'info', 'SyncLiveMatches job started', [
            'matchIds' => $this->matchIds,
            'timeout' => $this->timeout,
            'tries' => $this->tries,
        ]);

        $this->firestoreSettings = $settingsService->firestoreSettings();
        $this->cricbuzzSettings = $settingsService->cricbuzzSettings();

        $this->apiHost = $this->cricbuzzSettings['host'] ?? $this->apiHost;

        try {
            $this->firestore = $this->initializeClients();
            $this->log('initialize_clients', 'success', 'Firestore client initialised');
        } catch (Throwable $e) {
            $this->log('initialize_clients', 'error', 'Failed to initialise Firestore client', $this->exceptionContext($e));
            throw $e;
        }

        // $this->candidateMatchIds = $this->resolveCandidateMatchIds();

        // if (!empty($this->matchIds)) {
        //     $manualIds = array_map(static fn($id) => (string) $id, $this->matchIds);
        //     $this->candidateMatchIds = array_values(array_intersect($this->candidateMatchIds, $manualIds));
        // }

        // if (empty($this->candidateMatchIds)) {
        //     $windowStart = now()->copy()->subMinutes(15);
        //     $windowEnd = now()->copy()->addMinutes(35);

        //     $this->log('live_matches_candidates_empty', 'info', 'No matches scheduled inside the prefetch window', [
        //         'window_start' => $windowStart->toIso8601String(),
        //         'window_end' => $windowEnd->toIso8601String(),
        //         'requested_ids' => $this->matchIds,
        //     ]);

        //     $apiSummary = $this->getApiCallBreakdown();
        //     $this->log('job_completed', 'info', 'SyncLiveMatches job finished (no candidate matches)', [
        //         'synced' => 0,
        //         'skipped' => 0,
        //         'failures' => [],
        //         'api_calls' => $apiSummary,
        //     ]);

        //     return;
        // }

        $matches = $this->fetchLiveMatches();

        if (empty($matches)) {
            $apiSummary = $this->getApiCallBreakdown();
            $this->log('live_matches_empty', 'warning', 'No live matches returned by API', [
                'requested_ids' => $this->matchIds,
                'api_calls' => $apiSummary,
            ]);
            $this->log('job_completed', 'warning', 'SyncLiveMatches job finished', [
                'synced' => 0,
                'skipped' => 0,
                'failures' => [],
                'requested_ids' => $this->matchIds,
                'api_calls' => $apiSummary,
            ]);
            return;
        }

        $this->log('live_matches_fetched', 'info', 'Fetched live matches from API', [
            'match_count' => count($matches),
            'match_ids' => array_map(static fn($match) => $match['matchInfo']['matchId'] ?? null, $matches),
        ]);

        // $matches = $this->filterMatchesByStartWindow($matches);

        // if (empty($matches)) {
        //     $windowStart = now()->copy()->subMinutes(15);
        //     $windowEnd = now()->copy()->addMinutes(35);

        //     $this->log('live_matches_window_empty', 'info', 'Matches from API did not fall within the start time window', [
        //         'window_start' => $windowStart->toIso8601String(),
        //         'window_end' => $windowEnd->toIso8601String(),
        //         'candidate_matches' => $this->candidateMatchIds,
        //     ]);

        //     $apiSummary = $this->getApiCallBreakdown();
        //     $this->log('job_completed', 'info', 'SyncLiveMatches job finished (window filter)', [
        //         'synced' => 0,
        //         'skipped' => 0,
        //         'failures' => [],
        //         'api_calls' => $apiSummary,
        //     ]);

        //     return;
        // }

        $this->log('live_matches_filtered', 'info', 'Matches retained after window filter', [
            'filtered_count' => count($matches),
            // 'candidate_ids' => $this->candidateMatchIds,
        ]);

        $bulk = $this->firestore->bulkWriter([
            'maxBatchSize' => 100,
            'initialOpsPerSecond' => 20,
            'maxOpsPerSecond' => 60,
        ]);

        $matchFilterSet = !empty($this->candidateMatchIds)
            ? array_fill_keys($this->candidateMatchIds, false)
            : null;

        $synced = 0;
        $skipped = 0;
        $failures = [];

        foreach ($matches as $match) {
            $matchInfo = $match['matchInfo'] ?? null;
            if (!is_array($matchInfo)) {
                $skipped++;
                $this->log('match_skipped', 'warning', 'Match payload missing matchInfo block', [
                    'payload_keys' => array_keys($match),
                    'raw_payload' => $match,
                ]);
                continue;
            }

            $matchId = (string) ($matchInfo['matchId'] ?? '');
            if ($matchId === '') {
                $skipped++;
                $this->log('match_skipped', 'warning', 'Match info missing matchId', [
                    'matchInfo_keys' => array_keys($matchInfo),
                    'matchInfo' => $matchInfo,
                ]);
                continue;
            }

            if ($matchFilterSet !== null) {
                if (!array_key_exists($matchId, $matchFilterSet)) {
                    $this->log('match_skipped', 'info', 'Match did not match provided filter', [
                        'match_id' => $matchId,
                    ]);
                    continue;
                }

                $matchFilterSet[$matchId] = true;
            }

            try {
                $preparedMatchInfo = $this->prepareMatchInfo($matchInfo);
                $matchDocData = $this->prepareMatchDocument($match, $preparedMatchInfo);

                $matchRef = $this->firestore->collection('matches')->document($matchId);
                // $existingSnapshot = $matchRef->snapshot();

                // if ($existingSnapshot->exists()) {
                //     $existingData = $existingSnapshot->data();
                //     $adjustedData = $this->preserveRunProgress($matchDocData, $existingData ?? []);

                //     if ($adjustedData !== $matchDocData) {
                //         $this->log('match_runs_preserved', 'info', 'Retained higher run totals from existing snapshot', [
                //             'match_id' => $matchId,
                //         ]);
                //         $matchDocData = $adjustedData;
                //     }
                // }
               
                $bulk->set($matchRef, $matchDocData, ['merge' => true]);

                $synced++;

                $this->log('match_synced', 'success', 'Synced live match', [
                    'match_id' => $matchId,
                    'match_doc' => $matchDocData,
                    'match_info' => $preparedMatchInfo,
                ]);
            } catch (Throwable $e) {
                $failures[] = $matchId;
                $this->log('match_persist_failed', 'error', 'Failed to persist live match', $this->exceptionContext($e, [
                    'match_id' => $matchId,
                    'match_doc' => $match,
                ]));
            }
        }

        $bulk->flush(true);
        $bulk->close();

        if ($matchFilterSet !== null) {
            $missing = array_keys(array_filter($matchFilterSet, static fn($hit) => !$hit));
            if (!empty($missing)) {
                $this->log('match_filter_missing', 'warning', 'Provided match IDs not present in live feed', [
                    'missing_match_ids' => $missing,
                    'requested_ids' => $this->matchIds,
                ]);
            }
        }

        $apiSummary = $this->getApiCallBreakdown();

        $this->log('job_completed', empty($failures) ? 'success' : 'warning', 'SyncLiveMatches job finished', [
            'synced' => $synced,
            'skipped' => $skipped,
            'failures' => array_values(array_unique(array_filter($failures))),
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
     * @return array<int, array<string, mixed>>
     */
    private function fetchLiveMatches(): array
    {
        $endpoint = sprintf('http://%s/matches/v1/live', $this->apiHost);

        $headers = [
            'x-rapidapi-host' => $this->apiHost,
            'x-auth-user' => $this->apiKey,
            'Content-Type' => 'application/json; charset=UTF-8',
        ];

        $callId = $this->recordApiCall($endpoint, 'GET', 'live_matches');

        try {
            $response = Http::withHeaders($headers)->get($endpoint);
            $this->finalizeApiCall($callId, $response);
        } catch (Throwable $e) {
            $this->finalizeApiCall($callId, null, $e);
            $this->log('api_request_failed', 'error', 'Live matches request failed', $this->exceptionContext($e, [
                'endpoint' => $endpoint,
            ]));
            return [];
        }

        if (!$response->successful()) {
            $this->log('api_response_error', 'error', 'API returned error while fetching live matches', $this->responseContext($response, [
                'endpoint' => $endpoint,
            ]));
            return [];
        }

        $payload = $response->json();
        if (!is_array($payload)) {
            $this->log('api_response_invalid', 'error', 'Live matches API returned invalid payload', $this->responseContext($response, [
                'endpoint' => $endpoint,
            ]));
            return [];
        }

        $matches = [];

        $storeMatch = function (array $match) use (&$matches): void {
            $matchInfo = $match['matchInfo'] ?? null;
            $matchId = null;

            if (is_array($matchInfo) && isset($matchInfo['matchId'])) {
                $matchId = (string) $matchInfo['matchId'];
            }

            if ($matchId !== null && $matchId !== '') {
                $matches[$matchId] = $match;
                return;
            }

            $matches[] = $match;
        };

        if (!empty($payload['matchDetails']) && is_array($payload['matchDetails'])) {
            foreach ($payload['matchDetails'] as $detail) {
                if (!isset($detail['matchDetailsMap']['match']) || !is_array($detail['matchDetailsMap']['match'])) {
                    continue;
                }

                foreach ($detail['matchDetailsMap']['match'] as $match) {
                    if (!is_array($match)) {
                        continue;
                    }

                    $storeMatch($match);
                }
            }
        }

        if (!empty($payload['typeMatches']) && is_array($payload['typeMatches'])) {
            foreach ($payload['typeMatches'] as $typeBlock) {
                if (!is_array($typeBlock) || empty($typeBlock['seriesMatches']) || !is_array($typeBlock['seriesMatches'])) {
                    continue;
                }

                foreach ($typeBlock['seriesMatches'] as $seriesMatch) {
                    if (!is_array($seriesMatch)) {
                        continue;
                    }

                    $containers = [];

                    if (isset($seriesMatch['seriesAdWrapper']) && is_array($seriesMatch['seriesAdWrapper'])) {
                        $containers[] = $seriesMatch['seriesAdWrapper'];
                    } else {
                        $containers[] = $seriesMatch;
                    }

                    foreach ($containers as $container) {
                        if (!is_array($container) || empty($container['matches']) || !is_array($container['matches'])) {
                            continue;
                        }

                        foreach ($container['matches'] as $match) {
                            if (!is_array($match)) {
                                continue;
                            }

                            $storeMatch($match);
                        }
                    }
                }
            }
        }

        $this->log('api_response_parsed', 'info', 'Parsed live matches payload', [
            'raw_match_count' => count($matches),
            'payload' => $payload,
        ]);

        return array_values($matches);
    }

    /**
     * @param array<string, mixed> $matchInfo
     * @return array<string, mixed>
     */
    private function prepareMatchInfo(array $matchInfo): array
    {
        $prepared = $matchInfo;

        $prepared['matchId'] = (int) ($matchInfo['matchId'] ?? 0);
        if ($prepared['matchId'] === 0) {
            unset($prepared['matchId']);
        }

        if (isset($matchInfo['state']) && is_string($matchInfo['state'])) {
            $prepared['state_lowercase'] = strtolower($matchInfo['state']);
        }

        foreach (['startDate', 'endDate'] as $timestampKey) {
            if (isset($matchInfo[$timestampKey])) {
                $prepared[$timestampKey] = (int) $matchInfo[$timestampKey];
            }
        }

        foreach (['seriesStartDt', 'seriesEndDt'] as $timestampKey) {
            if (isset($matchInfo[$timestampKey])) {
                $prepared[$timestampKey] = (string) $matchInfo[$timestampKey];
            }
        }

        return $prepared;
    }

    public function array_change_key_case_recursive(array $array, int $case = CASE_LOWER): array
    {
        $result = [];

        foreach ($array as $key => $value) {
            $newKey = is_string($key)
                ? ($case === CASE_UPPER ? strtoupper($key) : strtolower($key))
                : $key;

            if (is_array($value)) {
                $result[$newKey] = array_change_key_case($value, $case);
            } else {
                $result[$newKey] = $value;
            }
        }

        return $result;
    }

    private function prepareMatchDocument(array $match, array $preparedMatchInfo): array
    {
        $document = $match;
        $document['matchInfo'] = $this->array_change_key_case_recursive($preparedMatchInfo, CASE_LOWER);
        $document['updatedAt'] = now()->valueOf();

        if (isset($preparedMatchInfo['matchId'])) {
            $document['matchId'] = (int) $preparedMatchInfo['matchId'];
        }

        if (isset($preparedMatchInfo['seriesId'])) {
            $document['seriesId'] = (int) $preparedMatchInfo['seriesId'];
        }

        if (isset($preparedMatchInfo['state_lowercase'])) {
            $document['matchInfo']['state_lowercase'] = $preparedMatchInfo['state_lowercase'];
        }

        return $document;
    }

    private function resolveCandidateMatchIds(): array
    {
        $upcoming = ['preview', 'upcoming', 'toss', 'toss delay', 'toss delayed'];
        $live = ['live', 'current', 'inprogress', 'in progress'];
        $states = array_values(array_unique(array_map('strtolower', array_merge($upcoming, $live))));

        $now = Carbon::now();
        $windowStartMs = $now->copy()->subMinutes(15)->getTimestampMs();
        $windowEndMs = $now->copy()->addMinutes(35)->getTimestampMs();

        try {
            $documents = $this->firestore
                ->collection('matches')
                ->where('matchInfo.state_lowercase', 'in', $states)
                ->where('matchInfo.startdate', '>=', $windowStartMs)
                ->where('matchInfo.startdate', '<=', $windowEndMs)
                ->documents();
        } catch (Throwable $e) {
            $this->log(
                'candidate_lookup_failed',
                'error',
                'Failed to resolve matches from Firestore',
                $this->exceptionContext($e)
            );
            return [];
        }

        $matches = [];

        foreach ($documents as $snap) {
            if ($snap->exists()) {
                $matches[] = (int) $snap->id();
            }
        }

        $matches = array_values(array_unique($matches));

        $this->log(
            'candidate_matches_resolved',
            'info',
            'Upcoming/just-started matches resolved from Firestore window',
            [
                'count' => count($matches),
                'window_start' => Carbon::createFromTimestampMs($windowStartMs)->toIso8601String(),
                'now' => $now->toIso8601String(),
                'window_end' => Carbon::createFromTimestampMs($windowEndMs)->toIso8601String(),
            ]
        );

        return $matches;
    }

    /**
     * @param array<int, array<string, mixed>> $matches
     * @return array<int, array<string, mixed>>
     */
    private function filterMatchesByStartWindow(array $matches): array
    {
        $windowStart = now()->copy()->subMinutes(15);
        $windowEnd = now()->copy()->addMinutes(35);

        $filtered = array_filter($matches, function ($match) use ($windowStart, $windowEnd) {
            $matchId = (int) data_get($match, 'matchInfo.matchId', '');

            if (!empty($this->candidateMatchIds) && !in_array($matchId, $this->candidateMatchIds, true)) {
                return false;
            }

            $startRaw = data_get($match, 'matchInfo.startDate');
            $startAt = $this->normaliseToCarbon($startRaw);

            if (!$startAt) {
                return false;
            }

            return $startAt->between($windowStart, $windowEnd, true);
        });

        return array_values($filtered);
    }

    private function normaliseToCarbon($value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (is_numeric($value)) {
            $numeric = (int) $value;

            if ($numeric > 1_000_000_000_000) {
                return Carbon::createFromTimestampMs($numeric);
            }

            return Carbon::createFromTimestamp($numeric);
        }

        if (is_string($value) && trim($value) !== '') {
            $trimmed = trim($value);

            if (is_numeric($trimmed)) {
                return $this->normaliseToCarbon((int) $trimmed);
            }

            try {
                return Carbon::parse($trimmed);
            } catch (\Exception $e) {
                return null;
            }
        }

        return null;
    }

    private function log(string $action, ?string $status, string $message, array $context = []): void
    {
        if (!isset($this->logger)) {
            return;
        }

        $this->logger->log($action, $status, $message, $context);
    }

    /**
     * @param array<string, mixed> $latest
     * @param array<string, mixed> $existing
     */
    private function preserveRunProgress(array $latest, array $existing): array
    {
        // foreach ($latest as $key => $value) {
        //     if ($key === 'runs' && isset($existing[$key]) && is_numeric($existing[$key]) && is_numeric($value)) {
        //         if ((float) $value < (float) $existing[$key]) {
        //             $latest[$key] = $existing[$key];
        //         }
        //         continue;
        //     }

        //     if (is_array($value) && isset($existing[$key]) && is_array($existing[$key])) {
        //         $latest[$key] = $this->preserveRunProgress($value, $existing[$key]);
        //     }
        // }

        return $latest;
    }
}
