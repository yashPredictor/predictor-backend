<?php

namespace App\Jobs;

use App\Services\AdminSettingsService;
use App\Services\SeriesStatsSyncLogger;
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
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class SyncSeriesStatsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, ApiLogging;

    public const CRON_KEY = 'series-stats';
    private const MATCHES_COLLECTION = 'matches';
    private const SERIES_STATS_COLLECTION = 'seriesStats';
    private const TOP_STATS_COLLECTION = 'topStatsList';
    private const TOP_STATS_DOCUMENT = 'all-types';
    private const MATCH_LOOKBACK_MINUTES = 90;

    public int $timeout = 900;
    public int $tries = 5;

    private ?FirestoreClient $firestore = null;
    private ?string $apiKey = null;

    private string $apiHost;
    private string $statsBaseUrl;

    private SeriesStatsSyncLogger $logger;
    private array $firestoreSettings = [];
    private array $cricbuzzSettings = [];

    /** @var array<int, array<string, string>> */
    private array $statTypes = [];

    /** @var array<string, bool> */
    private array $seriesEndCache = [];

    public function __construct(
        private readonly array $matchIds = [],
        private readonly array $seriesIds = [],
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
        $this->statsBaseUrl = sprintf('http://%s/stats/v1/', $this->apiHost);
        $this->logger = new SeriesStatsSyncLogger($this->runId);
        $this->runId = $this->logger->runId;
        $this->initApiLoggingContext($this->runId, self::CRON_KEY);

        $this->log('job_started', 'info', 'SyncSeriesStats job started', [
            'match_ids' => $this->matchIds,
            'series_ids' => $this->seriesIds,
            'timeout' => $this->timeout,
            'tries' => $this->tries,
        ]);

        $this->firestoreSettings = $settingsService->firestoreSettings();
        $this->cricbuzzSettings = $settingsService->cricbuzzSettings();

        $this->apiHost = $this->cricbuzzSettings['host'] ?? $this->apiHost;
        $this->statsBaseUrl = sprintf('http://%s/stats/v1/series/', $this->apiHost);

        try {
            $this->firestore = $this->initializeClients();
            $this->log('initialize_clients', 'success', 'Firestore client initialised');
        } catch (Throwable $e) {
            $this->log('initialize_clients', 'error', 'Failed to initialise Firestore client', $this->exceptionContext($e));
            throw $e;
        }

        $this->statTypes = $this->loadStatTypes();
        if (empty($this->statTypes)) {
            $this->log('stat_types_missing', 'warning', 'No stat types configured in topStatsList collection.');
            return;
        }
        
        $targetSeriesIds = $this->resolveSeriesIds();

        if (empty($targetSeriesIds)) {
            $apiSummary = $this->getApiCallBreakdown();
            $this->log('no_series', 'warning', 'No eligible series found for stats sync', [
                'match_ids' => $this->matchIds,
                'series_ids' => $this->seriesIds,
                'api_calls' => $apiSummary,
            ]);
            $this->log('job_completed', 'warning', 'SyncSeriesStats job finished', [
                'processed_series' => 0,
                'failures' => [],
                'api_calls' => $apiSummary,
            ]);
            return;
        }

        $this->log('series_resolved', 'info', 'Resolved series IDs for stats sync', [
            'count' => count($targetSeriesIds),
            'series_ids' => $targetSeriesIds,
        ]);

        $headers = $this->getHeaders();
        $synced = 0;
        $failures = [];

        foreach ($targetSeriesIds as $seriesId) {
            try {
                $syncedTypes = $this->syncSeries($seriesId, $headers);
            } catch (Throwable $e) {
                $failures[] = $seriesId;
                $this->log('series_sync_failed', 'error', 'Failed to sync series stats', $this->exceptionContext($e, [
                    'series_id' => $seriesId,
                ]));
                continue;
            }

            if ($syncedTypes > 0) {
                $synced++;
            }
        }

        $apiSummary = $this->getApiCallBreakdown();

        $this->log('job_completed', empty($failures) ? 'success' : 'warning', 'SyncSeriesStats job finished', [
            'processed_series' => count($targetSeriesIds),
            'synced_series' => $synced,
            'failures' => array_values(array_unique($failures)),
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
     * @return array<int, array<string, string>>
     */
    private function loadStatTypes(): array
    {
        if (!$this->firestore) {
            return [];
        }

        try {
            /** @var DocumentSnapshot $snapshot */
            $snapshot = $this->firestore
                ->collection(self::TOP_STATS_COLLECTION)
                ->document(self::TOP_STATS_DOCUMENT)
                ->snapshot();
        } catch (Throwable $e) {
            $this->log('stat_types_fetch_failed', 'error', 'Failed to fetch stat type configuration', $this->exceptionContext($e));
            return [];
        }

        if (!$snapshot->exists()) {
            return [];
        }

        $data = $snapshot->data();
        $list = data_get($data, 'list', []);

        if (!is_array($list)) {
            return [];
        }

        $flattened = [];

        foreach ($list as $section) {
            if (!is_array($section)) {
                continue;
            }

            $sectionCategory = (string) ($section['category'] ?? '');
            $types = $section['types'] ?? [];

            if (!is_array($types)) {
                continue;
            }

            foreach ($types as $type) {
                if (!is_array($type)) {
                    continue;
                }

                $value = trim((string) ($type['value'] ?? ''));
                if ($value === '') {
                    continue;
                }

                $flattened[] = [
                    'category' => (string) ($type['category'] ?? $sectionCategory),
                    'header' => (string) ($type['header'] ?? Str::title(str_replace('_', ' ', $value))),
                    'value' => $value,
                ];
            }
        }

        return $flattened;
    }

    /**
     * @return string[]
     */
    private function resolveSeriesIds(): array
    {
        $seriesIds = array_values(array_unique(array_map(static fn($id) => (string) $id, $this->seriesIds)));

        if (!empty($this->matchIds)) {
            $seriesFromMatches = $this->resolveSeriesFromMatches($this->matchIds);
            $seriesIds = array_merge($seriesIds, $seriesFromMatches);
        }

        if (!empty($seriesIds)) {
            $unique = array_values(array_unique($seriesIds));
            $filtered = array_values(array_filter($unique, fn($id) => $this->seriesHasEnded($id)));
            return $filtered;
        }

        if (!$this->firestore) {
            return [];
        }

        $cutoff = now()->copy()->subMinutes(self::MATCH_LOOKBACK_MINUTES)->valueOf();
        $seriesSet = [];

        try {
            $query = $this->firestore
                ->collection(self::MATCHES_COLLECTION)
                ->where('matchInfo.enddate', '<=', $cutoff);

            $documents = $query->documents();
        } catch (Throwable $e) {
            $this->log('series_resolve_failed', 'error', 'Failed to query matches for series stats sync', $this->exceptionContext($e, [
                'cutoff' => $cutoff,
            ]));
            return [];
        }

        foreach ($documents as $snapshot) {
            if (!$snapshot->exists()) {
                continue;
            }

            $data = $snapshot->data();
            $seriesId = $this->extractSeriesId($data);
            if ($seriesId !== null && $this->seriesHasEnded($seriesId)) {
                $seriesSet[$seriesId] = true;
            }
        }

        return array_keys($seriesSet);
    }

    /**
     * @param array<int|string, mixed> $matchIds
     * @return string[]
     */
    private function resolveSeriesFromMatches(array $matchIds): array
    {
        if (!$this->firestore) {
            return [];
        }

        $seriesSet = [];

        foreach ($matchIds as $matchId) {
            try {
                $snapshot = $this->firestore
                    ->collection(self::MATCHES_COLLECTION)
                    ->document($matchId)
                    ->snapshot();
            } catch (Throwable $e) {
                $this->log('match_lookup_failed', 'warning', 'Failed to fetch match document while resolving series', $this->exceptionContext($e, [
                    'match_id' => $matchId,
                ]));
                continue;
            }

            if (!$snapshot->exists()) {
                $this->log('match_missing', 'info', 'Match document not found while resolving series', [
                    'match_id' => $matchId,
                ]);
                continue;
            }

            $data = $snapshot->data();
            $endDate = (int) (data_get($data, 'matchInfo.enddate') ?? data_get($data, 'matchInfo.endDate') ?? 0);
            $cutoff = now()->copy()->subMinutes(self::MATCH_LOOKBACK_MINUTES)->valueOf();
            if ($endDate > $cutoff) {
                $this->log('match_skipped_window', 'info', 'Skipping match because end date has not passed lookback window', [
                    'match_id' => $matchId,
                    'match_end' => $endDate,
                    'cutoff' => $cutoff,
                ]);
                continue;
            }

            $seriesId = $this->extractSeriesId($data);
            if ($seriesId !== null && $this->seriesHasEnded($seriesId)) {
                $seriesSet[$seriesId] = true;
            }
        }

        return array_keys($seriesSet);
    }

    private function extractSeriesId(array $matchData): ?string
    {
        $seriesId = data_get($matchData, 'matchInfo.seriesId')
            ?? data_get($matchData, 'seriesId')
            ?? data_get($matchData, 'header.seriesId');

        if ($seriesId === null) {
            return null;
        }

        $seriesId = (string) $seriesId;

        return $seriesId !== '' ? $seriesId : null;
    }

    private function seriesHasEnded(string $seriesId): bool
    {
        if ($seriesId === '') {
            return false;
        }

        if (array_key_exists($seriesId, $this->seriesEndCache)) {
            return $this->seriesEndCache[$seriesId];
        }

        if (!$this->firestore) {
            return $this->seriesEndCache[$seriesId] = false;
        }

        try {
            $snapshot = $this->firestore
                ->collection('series')
                ->document($seriesId)
                ->snapshot();
        } catch (Throwable $e) {
            $this->log('series_lookup_failed', 'warning', 'Failed to read series document while verifying end timestamp', $this->exceptionContext($e, [
                'series_id' => $seriesId,
            ]));
            return $this->seriesEndCache[$seriesId] = false;
        }

        if (!$snapshot->exists()) {
            $this->seriesEndCache[$seriesId] = false;
            return false;
        }

        $data = $snapshot->data();
        $endTimestamp = data_get($data, 'endDtTimestamp')
            ?? data_get($data, 'endDt')
            ?? data_get($data, 'endDate');

        if ($endTimestamp === null || $endTimestamp === '') {
            $this->seriesEndCache[$seriesId] = false;
            return false;
        }

        if (!is_numeric($endTimestamp)) {
            if (is_string($endTimestamp) && is_numeric(trim($endTimestamp))) {
                $endTimestamp = (int) trim($endTimestamp);
            } else {
                $this->seriesEndCache[$seriesId] = false;
                return false;
            }
        }

        $endTimestamp = (int) $endTimestamp;
        $now = now();
        $twoHoursAgo = $now->copy()->subHours(2)->valueOf();

        // Only process series whose end timestamp is in the past but within the last 2 hours.
        $hasEnded = $endTimestamp > 0
            && $endTimestamp <= $now->valueOf()
            && $endTimestamp >= $twoHoursAgo;

        $this->seriesEndCache[$seriesId] = $hasEnded;

        return $hasEnded;
    }

    private function syncSeries(string $seriesId, array $headers): int
    {
        $statsRef = $this->firestore
            ->collection(self::SERIES_STATS_COLLECTION)
            ->document($seriesId);

        $existingData = [];
        try {
            $snapshot = $statsRef->snapshot();
            if ($snapshot->exists()) {
                $existingData = $snapshot->data() ?? [];
            }
        } catch (Throwable $e) {
            $this->log('series_stats_snapshot_failed', 'warning', 'Failed to read existing series stats snapshot', $this->exceptionContext($e, [
                'series_id' => $seriesId,
            ]));
        }

        $timestamp = now()->valueOf();
        $statsPayload = Arr::get($existingData, 'stats', []);
        if (!is_array($statsPayload)) {
            $statsPayload = [];
        }

        $syncedCount = 0;

        $typeChunks = array_chunk($this->statTypes, 5);

        foreach ($typeChunks as $typeChunk) {
            $responses = [];
            $callIds = [];

            try {
                $responses = Http::pool(function (Pool $pool) use ($typeChunk, $headers, $seriesId, &$callIds) {
                    $requests = [];
                    foreach ($typeChunk as $type) {
                        $value = $type['value'];
                        $url = $this->statsBaseUrl . $seriesId . '?uq=' . bin2hex(random_bytes(8));
                        $query = ['statsType' => $value];
                        $callIds[$value] = $this->recordApiCall($url, 'GET', 'series_stats_' . $value);
                        $requests[] = $pool
                            ->withHeaders($headers)
                            ->get($url, $query);
                    }
                    return $requests;
                });
            } catch (Throwable $e) {
                foreach ($callIds as $callId) {
                    $this->finalizeApiCall($callId, null, $e);
                }
                $this->log('series_stats_fetch_failed', 'error', 'Failed to fetch stats chunk', $this->exceptionContext($e, [
                    'series_id' => $seriesId,
                ]));
                continue;
            }

            foreach ($typeChunk as $index => $type) {
                $response = $responses[$index] ?? null;
                $callId = $callIds[$type['value']] ?? null;
                if ($response === null) {
                    if ($callId !== null) {
                        $this->finalizeApiCall($callId, null, new \RuntimeException('missing_response'));
                    }
                    $this->log('series_stats_missing', 'error', 'No response received for stats request', [
                        'series_id' => $seriesId,
                        'stat_value' => $type['value'],
                    ]);
                    continue;
                }

                if ($callId !== null) {
                    $this->finalizeApiCall($callId, $response);
                }

                if (!$response->successful()) {
                    $this->log('series_stats_fetch_error', 'error', 'Cricbuzz API returned error while fetching series stats', $this->responseContext($response, [
                        'series_id' => $seriesId,
                        'stat_value' => $type['value'],
                    ]));
                    continue;
                }

                $payload = $response->json();
                if (!is_array($payload) || empty($payload)) {
                    $this->log('series_stats_fetch_empty', 'warning', 'Series stats API returned empty payload', [
                        'series_id' => $seriesId,
                        'stat_value' => $type['value'],
                    ]);
                    continue;
                }

                $statsPayload[$type['value']] = array_merge(
                    [
                        'category' => $type['category'],
                        'header' => $type['header'],
                        'value' => $type['value'],
                        'lastFetched' => $timestamp,
                    ],
                    $payload
                );

                $syncedCount++;
            }
        }

        if ($syncedCount === 0) {
            return 0;
        }

        try {
            $statsRef->set([
                'seriesId' => (int) $seriesId,
                'stats' => $statsPayload,
                'lastFetched' => $timestamp,
                'updatedAt' => $timestamp,
            ], ['merge' => true]);

            $this->log('series_synced', 'success', 'Stored series stats in Firestore', [
                'series_id' => $seriesId,
                'synced_types' => $syncedCount,
            ]);
        } catch (Throwable $e) {
            $this->log('series_stats_persist_failed', 'error', 'Failed to persist series stats to Firestore', $this->exceptionContext($e, [
                'series_id' => $seriesId,
            ]));
        }

        return $syncedCount;
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
            'no-cache' => 'true',
            'Cache-Control' => 'no-cache',
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
