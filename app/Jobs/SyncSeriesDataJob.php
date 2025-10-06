<?php

namespace App\Jobs;

use App\Services\AdminSettingsService;
use App\Services\SeriesSyncLogger;
use App\Support\Logging\ApiLogging;
use App\Support\Queue\Middleware\RespectPauseWindow;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Throwable;

class SyncSeriesDataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, ApiLogging;

    private const SERIES_ENDPOINT     = 'series/v1/';

    private const MATCH_CENTER_PATH   = 'mcenter/v1/';

    private const MATCHES_COLLECTION  = 'matches';

    private ?FirestoreClient $firestore = null;

    private ?string $apiKey = null;

    private string $apiHost;

    private SeriesSyncLogger $logger;

    private array $firestoreSettings = [];

    private array $cricbuzzSettings  = [];

    private string $seriesBaseUrl;

    private string $matchCenterBaseUrl;

    private int $seriesStored = 0;

    private int $seriesFailed = 0;

    private int $matchesStored = 0;

    private int $matchesFailed = 0;

    /** @var array<int, array<string, string>> */
    private array $failures = [];

    public int $timeout = 3600;
    
    public int $tries = 5;

    public function __construct(
        private readonly array $seriesIds = [],
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
        $this->apiHost = config('services.cricbuzz.host', 'cricbuzz-cricket2.p.rapidapi.com');
        $this->logger  = new SeriesSyncLogger($this->runId);
        $this->runId   = $this->logger->runId;

        $this->logger->log('job_started', 'info', 'SyncSeriesData job started', [
            'seriesIds' => $this->seriesIds,
            'matchIds'  => $this->matchIds,
        ]);

        $settingsService         = app(AdminSettingsService::class);
        $this->firestoreSettings = $settingsService->firestoreSettings();
        $this->cricbuzzSettings  = $settingsService->cricbuzzSettings();

        $this->apiHost            = $this->cricbuzzSettings['host'] ?? $this->apiHost;
        $this->seriesBaseUrl      = sprintf('https://%s/%s', $this->apiHost, self::SERIES_ENDPOINT);
        $this->matchCenterBaseUrl = sprintf('https://%s/%s', $this->apiHost, self::MATCH_CENTER_PATH);

        try {
            $this->firestore = $this->initializeClients();
        } catch (Throwable $e) {
            $this->recordFailure('initialize_clients', 'Failed to bootstrap Firestore client(s)', [], $e);
            $this->finalize('error');
            return;
        }

        $seriesIdsForProcessing = array_values(array_unique($this->seriesIds));
        $matchIdsForProcessing  = array_values(array_unique($this->matchIds));
        $metadataRef            = $this->firestore->collection('seriesMetadata')->document('seriesMetadata');

        if (!empty($matchIdsForProcessing)) {
            $seriesIdsForProcessing = $this->augmentSeriesIdsFromMatches(
                $seriesIdsForProcessing,
                $matchIdsForProcessing
            );
        }

        $shouldSyncSeries = empty($matchIdsForProcessing) || !empty($this->seriesIds);

        if ($shouldSyncSeries) {
            $this->syncSeries($seriesIdsForProcessing, $matchIdsForProcessing, $metadataRef);
        } else {
            $this->logger->log('series_sync_skipped', 'info', 'Skipping series sync because only match IDs were provided');
        }

        $matchesSynced = $this->syncAllMatches($seriesIdsForProcessing, $matchIdsForProcessing);

        if (empty($this->seriesIds) && empty($this->matchIds)) {
            try {
                $metadataRef->set([
                    'lastMatchesFetched' => now()->valueOf(),
                    'matchCount'         => $matchesSynced,
                ], ['merge' => true]);
                $this->logger->log('metadata_update', 'success', 'Updated matches metadata', [
                    'matchesSynced' => $matchesSynced,
                ]);
            } catch (Throwable $e) {
                $this->recordFailure('metadata_update', 'Failed to refresh matches metadata document', [
                    'document' => 'seriesMetadata/matches',
                ], $e);
            }
        }

        $this->finalize(empty($this->failures) ? 'success' : 'warning');
    }

    private function initializeClients(): FirestoreClient
    {
        $keyPath   = $this->firestoreSettings['sa_json'] ?? config('services.firestore.sa_json');
        $projectId = $this->firestoreSettings['project_id'] ?? config('services.firestore.project_id');

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

        $this->apiKey = $this->cricbuzzSettings['key'] ?? config('services.cricbuzz.key');

        if (!$this->apiKey) {
            throw new \RuntimeException('Cricbuzz API key is not configured.');
        }

        return new FirestoreClient($options);
    }

    /**
     * @param array<int, string> $seriesIds
     * @param array<int, string> $matchIds
     * @return array<int, string>
     */
    private function augmentSeriesIdsFromMatches(array $seriesIds, array $matchIds): array
    {
        $resolved = $seriesIds;
        foreach ($matchIds as $matchId) {
            try {
                $matchSnapshot = $this->firestore->collection(self::MATCHES_COLLECTION)->document((string) $matchId)->snapshot();
            } catch (Throwable $e) {
                $this->recordFailure('series_lookup', "Failed to fetch match {$matchId} from Firestore", [
                    'match_id' => $matchId,
                ], $e);
                continue;
            }

            if (!$matchSnapshot->exists()) {
                $this->recordFailure('series_lookup', "Match {$matchId} not found in Firestore", [
                    'match_id' => $matchId,
                ]);
                continue;
            }

            $seriesFromMatch = $matchSnapshot->data()['seriesId'] ?? null;
            if ($seriesFromMatch === null || $seriesFromMatch === '') {
                $this->recordFailure('series_lookup', "Series ID missing for match {$matchId}", [
                    'match_id'      => $matchId,
                    'match_payload' => $matchSnapshot->data(),
                ]);
                continue;
            }

            $resolved[] = (string) $seriesFromMatch;
        }

        return array_values(array_unique($resolved));
    }

    /**
     * @param array<int, string> $seriesIdsForProcessing
     * @param array<int, string> $matchIdsForProcessing
     */
    private function syncSeries(array $seriesIdsForProcessing, array $matchIdsForProcessing, $metadataRef): void
    {
        $seriesTypes      = ['international', 'league', 'domestic', 'women'];
        $seriesTargetSet  = !empty($seriesIdsForProcessing) ? array_fill_keys($seriesIdsForProcessing, false) : null;
        $processedSeries  = [];
        $bulk             = $this->firestore->bulkWriter([
            'maxBatchSize'        => 100,
            'initialOpsPerSecond' => 20,
            'maxOpsPerSecond'     => 60,
        ]);

        foreach ($seriesTypes as $type) {
            $response = $this->makeApiRequest($this->seriesBaseUrl . $type, 'series_type');
            if ($response === null) {
                continue;
            }

            $json = $response->json();
            if (!$response->successful() || !isset($json['seriesMapProto'])) {
                $this->recordFailure('series_fetch', "Invalid response for series type {$type}", $this->responseContext($response, [
                    'series_type' => $type,
                    'url'         => $this->seriesBaseUrl . $type,
                ]), null, 'warning');
                continue;
            }

            try {
                $seriesDataRef = $this->firestore->collection('seriesData')->document($type);
                $bulk->set($seriesDataRef, $json);
                $this->logger->log('series_data_store', 'success', "Stored series data block {$type}", [
                    'series_type'              => $type,
                    'series_map_proto_segments' => is_array($json['seriesMapProto'] ?? null)
                        ? count($json['seriesMapProto'])
                        : 0,
                ]);
            } catch (Throwable $e) {
                $this->recordFailure('series_data_store', "Failed to store series data block {$type}", [
                    'series_type' => $type,
                ], $e);
            }

            foreach ($json['seriesMapProto'] as $monthData) {
                if (!isset($monthData['series']) || !is_array($monthData['series'])) {
                    continue;
                }

                foreach ($monthData['series'] as $series) {
                    $sid = (string) ($series['id'] ?? '');
                    if ($sid === '') {
                        $this->recordFailure('series_store', 'Encountered series without ID', [
                            'series_payload' => $series,
                            'series_type'    => $type,
                        ]);
                        $this->seriesFailed++;
                        continue;
                    }

                    if ($seriesTargetSet !== null && !array_key_exists($sid, $seriesTargetSet)) {
                        continue;
                    }

                    if (isset($processedSeries[$sid])) {
                        continue;
                    }

                    try {
                        $seriesRef = $this->firestore->collection('series')->document($sid);
                        $seriesWithCategory = array_merge($series, [
                            'id'               => $sid,
                            'category'         => $type,
                            'startDtTimestamp' => isset($series['startDt']) ? (int) $series['startDt'] : null,
                            'endDtTimestamp'   => isset($series['endDt']) ? (int) $series['endDt'] : null,
                        ]);

                        $bulk->set($seriesRef, $seriesWithCategory, ['merge' => true]);
                        $this->seriesStored++;
                        $processedSeries[$sid] = true;
                        $this->logger->log('series_store', 'success', "Stored series {$sid}", [
                            'category'       => $type,
                            'series_payload' => $seriesWithCategory,
                        ]);

                        if ($seriesTargetSet !== null) {
                            $seriesTargetSet[$sid] = true;
                        }

                        if ($seriesTargetSet !== null && !in_array(false, $seriesTargetSet, true)) {
                            break 3;
                        }
                    } catch (Throwable $e) {
                        $this->seriesFailed++;
                        $this->recordFailure('series_store', "Failed to store series {$sid}", [
                            'series_payload' => $seriesWithCategory,
                            'series_type'    => $type,
                        ], $e);
                    }
                }
            }
        }

        $bulk->flush(true);
        $bulk->close();

        if (empty($this->seriesIds) && empty($matchIdsForProcessing)) {
            try {
                $metadataRef->set([
                    'lastFetched' => now()->valueOf(),
                    'seriesCount' => $this->seriesStored,
                ], ['merge' => true]);
                $this->logger->log('metadata_update', 'success', 'Updated series metadata', [
                    'seriesStored' => $this->seriesStored,
                ]);
            } catch (Throwable $e) {
                $this->recordFailure('metadata_update', 'Failed to refresh series metadata document', [
                    'document' => 'seriesMetadata/series',
                ], $e);
            }
        }

        if ($seriesTargetSet !== null) {
            $missingSeries = array_keys(array_filter($seriesTargetSet, fn($processed) => !$processed));
            if (!empty($missingSeries)) {
                $this->logger->log('series_missing', 'warning', 'Series IDs not found in remote API response', [
                    'seriesIds' => $missingSeries,
                ]);
            }
        }
    }

    /**
     * @param array<int, string> $seriesIdFilters
     * @param array<int, string> $matchIdFilters
     */
    private function syncAllMatches(array $seriesIdFilters, array $matchIdFilters): int
    {
        $seriesDocs = $this->firestore->collection('series')->documents();

        $bulk = $this->firestore->bulkWriter([
            'maxBatchSize'        => 100,
            'initialOpsPerSecond' => 20,
            'maxOpsPerSecond'     => 60,
        ]);

        $seriesFilterSet = !empty($seriesIdFilters) ? array_fill_keys($seriesIdFilters, false) : null;
        $matchFilterSet  = !empty($matchIdFilters) ? array_fill_keys($matchIdFilters, false) : null;

        $seenMatchIds = [];

        foreach ($seriesDocs as $seriesDoc) {
            if (!$seriesDoc->exists()) {
                continue;
            }

            $seriesData = $seriesDoc->data();
            $seriesId   = (string) ($seriesData['id'] ?? $seriesDoc->id());
            $category   = $seriesData['category'] ?? null;

            if ($seriesFilterSet !== null && !array_key_exists($seriesId, $seriesFilterSet)) {
                continue;
            }

            if ($seriesFilterSet !== null) {
                $seriesFilterSet[$seriesId] = true;
            }

            $response = $this->makeApiRequest($this->seriesBaseUrl . $seriesId, 'series_matches');
            if ($response === null) {
                continue;
            }

            $json = $response->json();
            if (!$response->successful() || !isset($json['matchDetails'])) {
                $this->recordFailure('match_fetch', "Invalid response for series {$seriesId}", $this->responseContext($response, [
                    'series_id' => $seriesId,
                    'category'  => $category,
                ]), null, 'warning');
                continue;
            }

            foreach ($json['matchDetails'] as $detail) {
                if (!isset($detail['matchDetailsMap']['match'])) {
                    continue;
                }

                foreach ($detail['matchDetailsMap']['match'] as $match) {
                    $matchInfo         = Arr::get($match, 'matchInfo');
                    $originalMatchInfo = is_array($matchInfo) ? $matchInfo : [];

                    if (!is_array($matchInfo)) {
                        continue;
                    }

                    $matchId = $matchInfo['matchId'] ?? '';
                    if ($matchId === '') {
                        $this->recordFailure('match_store', 'Encountered match without ID', [
                            'series_id'     => $seriesId,
                            'category'      => $category,
                            'match_payload' => $match,
                        ]);
                        $this->matchesFailed++;
                        continue;
                    }

                    if ($matchFilterSet !== null && !array_key_exists($matchId, $matchFilterSet)) {
                        continue;
                    }

                    if (isset($seenMatchIds[$matchId])) {
                        continue;
                    }
                    $seenMatchIds[$matchId] = true;

                    if ($matchFilterSet !== null) {
                        $matchFilterSet[$matchId] = true;
                    }

                    $matchCenter = $this->fetchMatchCenterInfo($matchId);
                    if (is_array($matchCenter) && !empty($matchCenter)) {
                        $this->logger->log('match_center_enriched', 'info', "Enriched match {$matchId} with mcenter payload", [
                            'series_id' => $seriesId,
                            'category'  => $category,
                        ]);
                        $matchInfo = $matchCenter;
                    } else {
                        $this->logger->log('match_center_fallback', 'info', "Using original matchInfo for match {$matchId}", [
                            'series_id' => $seriesId,
                            'category'  => $category,
                        ]);
                    }

                    $state     = $this->resolveMatchState($matchInfo, $originalMatchInfo);
                    $startDate = $this->resolveMatchTimestamp($matchInfo, $originalMatchInfo, 'start');
                    $endDate   = $this->resolveMatchTimestamp($matchInfo, $originalMatchInfo, 'end') ?? $startDate;

                    try {
                        $matchWithMeta = array_merge($match, [
                            'category'  => $category,
                            'seriesId'  => (int) $seriesId,
                            'matchInfo' => array_merge($matchInfo, [
                                'state_lowercase' => $state !== null ? strtolower($state) : null,
                                'startdate'       => $startDate,
                                'enddate'         => $endDate,
                            ]),
                            'updatedAt' => now()->getTimestamp() * 1000,
                        ]);

                        $matchRef = $this->firestore->collection('matches')->document($matchId);
                        $bulk->set($matchRef, $matchWithMeta, ['merge' => true]);
                        $this->matchesStored++;
                        $this->logger->log('match_store', 'success', "Stored match {$matchId}", [
                            'series_id'    => $seriesId,
                            'category'     => $category,
                            'match_payload'=> $matchWithMeta,
                        ]);
                    } catch (Throwable $e) {
                        $this->matchesFailed++;
                        $this->recordFailure('match_store', "Failed to store match {$matchId}", [
                            'series_id'     => $seriesId,
                            'category'      => $category,
                            'match_payload' => $match,
                        ], $e);
                    }
                }
            }

            $bulk->flush(true);
        }

        $bulk->flush(true);
        $bulk->close();

        if ($seriesFilterSet !== null) {
            $missing = array_keys(array_filter($seriesFilterSet, static fn($processed) => !$processed));
            if (!empty($missing)) {
                $this->logger->log('series_missing', 'warning', 'No stored series matched IDs during match sync', [
                    'seriesIds' => $missing,
                ]);
            }
        }

        if ($matchFilterSet !== null) {
            $missingMatches = array_keys(array_filter($matchFilterSet, static fn($processed) => !$processed));
            if (!empty($missingMatches)) {
                $this->logger->log('match_missing', 'warning', 'Matches not found in remote API response', [
                    'matchIds' => $missingMatches,
                ]);
            }
        }

        return $this->matchesStored;
    }

    private function fetchMatchCenterInfo(string $matchId): ?array
    {
        $url = $this->matchCenterBaseUrl . $matchId;
        $response = $this->makeApiRequest($url, 'match_center');

        if ($response === null) {
            return null;
        }

        $payload = $response->json();
        if (!is_array($payload) || empty($payload)) {
            $this->logger->log('match_center_invalid', 'warning', 'Match center API returned empty payload', [
                'match_id' => $matchId,
                'url'      => $url,
            ]);
            return null;
        }

        return $payload;
    }

    private function resolveMatchState(array $primary, array $fallback = []): ?string
    {
        $candidates = [
            Arr::get($primary, 'state'),
            Arr::get($primary, 'status'),
            Arr::get($primary, 'matchHeader.state'),
            Arr::get($primary, 'matchInfo.state'),
            Arr::get($fallback, 'state'),
            Arr::get($fallback, 'matchHeader.state'),
            Arr::get($fallback, 'matchInfo.state'),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    private function resolveMatchTimestamp(array $primary, array $fallback, string $type): ?int
    {
        $keys = $type === 'start'
            ? ['startDate', 'matchHeader.matchStartTimestamp', 'matchInfo.startDate', 'scheduleInfo.matchStartTimestamp']
            : ['endDate', 'matchHeader.matchEndTimestamp', 'matchInfo.endDate', 'scheduleInfo.matchEndTimestamp'];

        $candidates = [];
        foreach ($keys as $key) {
            $candidates[] = Arr::get($primary, $key);
        }
        foreach ($keys as $key) {
            $candidates[] = Arr::get($fallback, $key);
        }

        foreach ($candidates as $value) {
            $normalized = $this->normalizeTimestamp($value);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    private function normalizeTimestamp($value): ?int
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return (int) ($value->getTimestamp() * 1000);
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed !== '' && is_numeric($trimmed)) {
                return (int) $trimmed;
            }
        }

        return null;
    }

    private function makeApiRequest(string $url, string $action)
    {
        try {
            $this->recordApiCall($url, 'GET', $action);

            $response = Http::withHeaders([
                'x-rapidapi-host' => $this->apiHost,
                'x-rapidapi-key'  => $this->apiKey,
            ])->get($url);

            $context = $this->responseContext($response, [
                'action' => $action,
                'url'    => $url,
            ]);

            if (!$response->successful()) {
                $this->recordFailure('api_call', "GET {$url} returned {$response->status()}", $context);
                return null;
            }

            $this->logger->log('api_call', 'success', "GET {$url}", $context);

            return $response;
        } catch (Throwable $e) {
            $context = $this->exceptionContext($e, [
                'action' => $action,
                'url'    => $url,
            ]);
            $this->recordFailure('api_call', "GET {$url} threw an exception", $context, $e);

            return null;
        }
    }

    private function recordFailure(string $action, string $message, array $context = [], ?Throwable $exception = null, $status = 'error'): void
    {
        if ($exception !== null) {
            $context = $this->exceptionContext($exception, $context);
        }

        $context = array_merge(['action' => $action], $context);

        $this->failures[] = [
            'action'  => $action,
            'message' => $message,
            'context' => $context,
        ];

        $this->logger->log($action, $status, $message, $context);
    }

    private function finalize(string $status): void
    {
        $apiCallSummary = $this->getApiCallBreakdown();

        $this->logger->log('job_finished', $status, 'SyncSeriesData job completed', [
            'apiCalls'       => $apiCallSummary['total'],
            'apiCallBreakdown' => $apiCallSummary['breakdown'],
            'seriesStored'   => $this->seriesStored,
            'seriesFailed'   => $this->seriesFailed,
            'matchesStored'  => $this->matchesStored,
            'matchesFailed'  => $this->matchesFailed,
            'failures'       => $this->failures,
            'runId'          => $this->runId,
        ]);
    }
}
