<?php
namespace App\Jobs;

use App\Services\AdminSettingsService;
use App\Services\SeriesSquadSyncLogger;
use App\Support\Logging\ApiLogging;
use App\Support\Queue\Middleware\RespectPauseWindow;
use Google\Cloud\Firestore\DocumentSnapshot;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class SyncSeriesSquadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, ApiLogging;

    public const CRON_KEY = 'series-squads';
    private const SQUADS_COLLECTION = 'seriesSquads';
    public int $timeout = 600;
    public int $tries = 5;

    private ?FirestoreClient $firestore = null;
    private ?string $apiKey = null;
    private string $apiHost;
    private SeriesSquadSyncLogger $logger;
    private array $firestoreSettings = [];
    private array $cricbuzzSettings = [];

    private int $seriesSynced = 0;
    private int $seriesSkipped = 0;
    private int $seriesFailed = 0;

    public function __construct(
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
        $this->logger = new SeriesSquadSyncLogger($this->runId);
        $this->runId = $this->logger->runId;
        $this->initApiLoggingContext($this->runId, self::CRON_KEY);

        $this->log('job_started', 'info', 'SyncSeriesSquad job started', [
            'series_ids' => $this->seriesIds,
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

        $candidateSeries = $this->resolveCandidateSeries();

        if (!empty($this->seriesIds)) {
            $requested = array_map(static fn($id) => (string) $id, $this->seriesIds);
            $candidateSeries = array_values(array_intersect($candidateSeries, $requested));
        }

        if (empty($candidateSeries)) {
            $this->log('series_candidates_empty', 'info', 'No series require squad syncing within 30 days', [
                'series_ids' => $this->seriesIds,
            ]);

            $this->finalize('info');
            return;
        }

        foreach ($candidateSeries as $seriesId) {
            $this->processSeries($seriesId);
        }

        $status = $this->seriesFailed > 0 ? 'warning' : 'success';
        $this->finalize($status);
    }

    private function processSeries(string $seriesId): void
    {
        try {
            $payload = $this->fetchSeriesSquad($seriesId);

            if ($payload === null) {
                $this->seriesFailed++;
                $this->log('series_squad_empty', 'error', 'Series squads API returned no usable payload', [
                    'series_id' => $seriesId,
                ]);
                return;
            }

            $this->storeSquad($seriesId, $payload);
            $this->seriesSynced++;

            $this->log('series_squad_synced', 'success', 'Stored series squads in Firestore', [
                'series_id' => $seriesId,
            ]);
        } catch (Throwable $e) {
            $this->seriesFailed++;
            $this->log('series_squad_failed', 'error', 'Failed to sync series squads', $this->exceptionContext($e, [
                'series_id' => $seriesId,
            ]));
        }
    }

    private function fetchSeriesSquad(string $seriesId): ?array
    {
        $baseUrl = sprintf('http://%s/series/v1/%s/squads', $this->apiHost, $seriesId);

        $response = $this->performApiRequest($baseUrl, 'series_squads');

        if ($response === null) {
            return null;
        }

        $payload = $response->json();

        if (!is_array($payload) || empty($payload['squads']) || !is_array($payload['squads'])) {
            return null;
        }

        $playerUrlPrefix = $baseUrl . '/';

        foreach ($payload['squads'] as $index => $squad) {
            $squadId = $squad['squadId'] ?? null;

            if (!$squadId) {
                $payload['squads'][$index]['players'] = [];
                continue;
            }

            $playerResponse = $this->performApiRequest($playerUrlPrefix . $squadId, 'series_squads_players');

            if ($playerResponse === null) {
                $this->log('series_squad_players_failed', 'warning', 'Series squad players API returned no response', [
                    'series_id' => $seriesId,
                    'squad_id' => $squadId,
                ]);

                $payload['squads'][$index]['players'] = [];
                continue;
            }

            $playerPayload = $playerResponse->json();

            $players = array_filter($playerPayload['player'] ?? [], function ($p) {
                return empty($p['isHeader']);
            });

            $payload['squads'][$index]['players'] = array_values($players);
        }

        return $payload;
    }

    private function storeSquad(string $seriesId, array $payload): void
    {
        $documentPayload = $payload + [
            'seriesId' => (int) $seriesId,
            'fetched_at' => now()->valueOf(),
            'serverTime' => now()->toIso8601String(),
        ];

        $this->firestore
            ->collection(self::SQUADS_COLLECTION)
            ->document($seriesId)
            ->set($documentPayload);
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
            throw new \RuntimeException('Firestore project id missing. Configure FIRESTORE_PROJECT_ID or provide service account JSON with project_id.');
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
    private function resolveCandidateSeries(): array
    {
        try {
            $seriesDocs = $this->firestore
                ->collection('series')
                ->where('startDtTimestamp', '>=', now()->valueOf())
                ->where('startDtTimestamp', '<=', now()->copy()->addDays(30)->valueOf())
                ->documents();

        } catch (Throwable $e) {
            $this->log('series_query_failed', 'error', 'Unable to fetch series shortlist from Firestore', $this->exceptionContext($e));

            return [];
        }

        $candidates = [];
        $squadCollection = $this->firestore->collection(self::SQUADS_COLLECTION);

        /** @var DocumentSnapshot $seriesDoc */
        foreach ($seriesDocs as $seriesDoc) {
            if (!$seriesDoc->exists()) {
                continue;
            }

            $data = $seriesDoc->data();
            $seriesId = (string) ($data['id'] ?? $seriesDoc->id());

            if ($seriesId === '') {
                continue;
            }

            $startRaw = $data['startDt'] ?? ($data['startDate'] ?? null);
            if (!$startRaw) {
                continue;
            }

            $startAt = $this->normaliseToCarbon($startRaw);
            if (!$startAt) {
                continue;
            }

            if ($startAt->greaterThan(now()->copy()->addDays(30))) {
                continue;
            }

            // try {
            //     if ($squadCollection->document($seriesId)->snapshot()->exists()) {
            //         continue;
            //     }
            // } catch (Throwable $e) {
            //     $this->log('series_squad_lookup_failed', 'warning', 'Failed to check existing series squad document', $this->exceptionContext($e, [
            //         'series_id' => $seriesId,
            //     ]));
            //     continue;
            // }

            $candidates[] = $seriesId;
        }

        $candidates = array_values(array_unique($candidates));

        $this->log('series_candidates_resolved', 'info', 'Resolved series requiring squad sync', [
            'count' => count($candidates),
        ]);

        return $candidates;
    }

    private function performApiRequest(string $url, string $tag)
    {
        $callId = $this->recordApiCall($url, 'GET', $tag);

        try {
            $response = Http::withHeaders([
                'x-rapidapi-host' => $this->apiHost,
                'x-auth-user' => $this->apiKey,
                'Content-Type' => 'application/json; charset=UTF-8',
                'no-cache' => 'true',
                'Cache-Control' => 'no-cache',
            ])->get($url);

            $this->finalizeApiCall($callId, $response);

            if (!$response->successful()) {
                $this->log('series_squad_api_error', 'error', 'Series squads API returned an error response', $this->responseContext($response, [
                    'url' => $url,
                ]));

                return null;
            }

            return $response;
        } catch (Throwable $e) {
            $this->finalizeApiCall($callId, null, $e);
            $this->log('series_squad_api_exception', 'error', 'Series squads API request failed', $this->exceptionContext($e, [
                'url' => $url,
            ]));

            return null;
        }
    }

    private function finalize(string $status): void
    {
        $apiSummary = $this->getApiCallBreakdown();

        $this->log('job_completed', $status, 'SyncSeriesSquad job finished', [
            'series_synced' => $this->seriesSynced,
            'series_skipped' => $this->seriesSkipped,
            'series_failed' => $this->seriesFailed,
            'requested_ids' => $this->seriesIds,
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

    private function exceptionContext(Throwable $e, array $context = []): array
    {
        return array_merge($context, [
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'trace' => Str::limit($e->getTraceAsString(), 2048),
        ]);
    }
}
