<?php

namespace App\Jobs;

use App\Services\AdminSettingsService;
use App\Services\SeriesVenuesSyncLogger;
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
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncSeriesVenuesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, ApiLogging;

    public const CRON_KEY = 'series-venues';
    private const SERIES_COLLECTION = 'series';
    private const SERIES_VENUES_COLLECTION = 'seriesVenues';
    private const LOOKAHEAD_DAYS = 30;

    public int $timeout = 900;
    public int $tries = 5;

    private ?FirestoreClient $firestore = null;
    private ?string $apiKey = null;
    private string $apiHost;
    private string $baseUrl;
    private SeriesVenuesSyncLogger $logger;
    private array $firestoreSettings = [];
    private array $cricbuzzSettings = [];

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
        $this->baseUrl = sprintf('http://%s/series/v1/', $this->apiHost);
        $this->logger = new SeriesVenuesSyncLogger($this->runId);
        $this->runId = $this->logger->runId;
        $this->initApiLoggingContext($this->runId, self::CRON_KEY);

        $this->log('job_started', 'info', 'SyncSeriesVenues job started', [
            'series_ids' => $this->seriesIds,
            'timeout' => $this->timeout,
            'tries' => $this->tries,
        ]);

        $this->firestoreSettings = $settingsService->firestoreSettings();
        $this->cricbuzzSettings = $settingsService->cricbuzzSettings();

        $this->apiHost = $this->cricbuzzSettings['host'] ?? $this->apiHost;
        $this->baseUrl = sprintf('http://%s/series/v1/', $this->apiHost);

        try {
            $this->firestore = $this->initializeClients();
            $this->log('initialize_clients', 'success', 'Firestore client initialised');
        } catch (Throwable $e) {
            $this->log('initialize_clients', 'error', 'Failed to initialise Firestore client', $this->exceptionContext($e));
            throw $e;
        }

        $candidateSeries = $this->resolveSeriesIds();
        
        if (empty($candidateSeries)) {
            $this->log('no_series', 'info', 'No upcoming series require venue sync');
            $this->finalize('info', 0, []);
            return;
        }

        $headers = $this->getHeaders();
        $synced = 0;
        $failures = [];

        foreach ($candidateSeries as $seriesId) {
            try {
                if ($this->syncSeriesVenues($seriesId, $headers)) {
                    $synced++;
                }
            } catch (Throwable $e) {
                $failures[] = $seriesId;
                $this->log('series_sync_failed', 'error', 'Failed to sync venues for series', $this->exceptionContext($e, [
                    'series_id' => $seriesId,
                ]));
            }
        }

        $this->finalize(empty($failures) ? 'success' : 'warning', $synced, $failures);
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
    private function resolveSeriesIds(): array
    {
        $seriesIds = array_values(array_unique(array_map(static fn($id) => (string) $id, $this->seriesIds)));

        if ($this->firestore === null) {
            return [];
        }

        $now = Carbon::now();
        $windowEnd = $now->copy()->addDays(self::LOOKAHEAD_DAYS);

        if (empty($seriesIds)) {
            try {
                $query = $this->firestore
                    ->collection(self::SERIES_COLLECTION)
                    ->where('startDtTimestamp', '>=', $now->valueOf())
                    ->where('startDtTimestamp', '<=', $windowEnd->valueOf());

                $documents = $query->documents();
            } catch (Throwable $e) {
                $this->log('series_lookup_failed', 'error', 'Failed to query upcoming series for venue sync', $this->exceptionContext($e));
                return [];
            }

            $seriesIds = [];
            foreach ($documents as $snapshot) {
                if (!$snapshot->exists()) {
                    continue;
                }

                $data = $snapshot->data();
                $seriesId = (string) ($data['id'] ?? $snapshot->id());
                if ($seriesId === '') {
                    continue;
                }

                $seriesIds[] = $seriesId;
            }
        }

        if (empty($seriesIds)) {
            return [];
        }

        $seriesIds = array_values(array_unique($seriesIds));

        $eligible = [];
        
        foreach ($seriesIds as $seriesId) {
            if ($this->seriesHasVenueData($seriesId)) {
                continue;
            }

            if (!$this->seriesStartsWithinWindow($seriesId, $now, $windowEnd)) {
                continue;
            }

            $eligible[] = $seriesId;
        }

        return $eligible;
    }

    private function seriesHasVenueData(string $seriesId): bool
    {
        if ($seriesId === '') {
            return true;
        }

        try {
            $snapshot = $this->firestore
                ->collection(self::SERIES_VENUES_COLLECTION)
                ->document($seriesId)
                ->snapshot();
        } catch (Throwable $e) {
            $this->log('series_venues_lookup_failed', 'warning', 'Failed to check existing series venues', $this->exceptionContext($e, [
                'series_id' => $seriesId,
            ]));
            return false;
        }

        if (!$snapshot->exists()) {
            return false;
        }

        $data = $snapshot->data();
        $venues = data_get($data, 'data.seriesVenue');

        return is_array($venues) && !empty($venues);
    }

    private function seriesStartsWithinWindow(string $seriesId, Carbon $windowStart, Carbon $windowEnd): bool
    {
        try {
            $snapshot = $this->firestore
                ->collection(self::SERIES_COLLECTION)
                ->document($seriesId)
                ->snapshot();
        } catch (Throwable $e) {
            $this->log('series_snapshot_failed', 'warning', 'Failed to read series snapshot while resolving window', $this->exceptionContext($e, [
                'series_id' => $seriesId,
            ]));
            return false;
        }

        if (!$snapshot->exists()) {
            return false;
        }

        $data = $snapshot->data();
        $start = data_get($data, 'startDtTimestamp');

        if (!is_numeric($start)) {
            return false;
        }

        $start = (int) $start;

        return $start >= $windowStart->valueOf() && $start <= $windowEnd->valueOf();
    }

    private function syncSeriesVenues(string $seriesId, array $headers): bool
    {
        $url = $this->baseUrl . $seriesId . '/venues?uq=' . bin2hex(random_bytes(8));
        $callId = $this->recordApiCall($url, 'GET', 'series_venues');

        try {
            $response = Http::withHeaders($headers)->get($url);
            $this->finalizeApiCall($callId, $response);
        } catch (Throwable $e) {
            $this->finalizeApiCall($callId, null, $e);
            $this->log('series_venues_fetch_failed', 'error', 'Series venues API request failed', $this->exceptionContext($e, [
                'series_id' => $seriesId,
                'url' => $url,
            ]));
            return false;
        }

        if (!$response->successful()) {
            $this->log('series_venues_fetch_error', 'error', 'Series venues API returned error', $this->responseContext($response, [
                'series_id' => $seriesId,
                'url' => $url,
            ]));
            return false;
        }

        $payload = $response->json();
        if (!is_array($payload) || empty($payload)) {
            $this->log('series_venues_fetch_empty', 'warning', 'Series venues API returned empty payload', [
                'series_id' => $seriesId,
                'url' => $url,
            ]);
            return false;
        }

        $documentPayload = [
            'data' => $payload,
            'lastFetched' => now()->valueOf(),
        ];

        try {
            $this->firestore
                ->collection(self::SERIES_VENUES_COLLECTION)
                ->document($seriesId)
                ->set($documentPayload, ['merge' => true]);

            $this->log('series_synced', 'success', 'Stored series venues in Firestore', [
                'series_id' => $seriesId,
            ]);

            return true;
        } catch (Throwable $e) {
            $this->log('series_venues_persist_failed', 'error', 'Failed to persist series venues to Firestore', $this->exceptionContext($e, [
                'series_id' => $seriesId,
            ]));
        }

        return false;
    }

    private function finalize(string $status, int $synced, array $failures): void
    {
        $apiSummary = $this->getApiCallBreakdown();

        $this->log('job_completed', $status, 'SyncSeriesVenues job finished', [
            'series_considered' => $synced + count($failures),
            'synced' => $synced,
            'failures' => array_values(array_unique($failures)),
            'series_ids' => $this->seriesIds,
            'api_calls' => $apiSummary,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function getHeaders(): array
    {
        return [
            'x-rapidapi-host' => $this->apiHost,
            'x-auth-user'  => $this->apiKey,
            'Content-Type'    => 'application/json; charset=UTF-8',
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
