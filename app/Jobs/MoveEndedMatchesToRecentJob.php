<?php

namespace App\Jobs;

use App\Services\AdminSettingsService;
use App\Services\RecentMatchStatusLogger;
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
use Throwable;

class MoveEndedMatchesToRecentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, ApiLogging;

    private const CRON_KEY = 'recent-matches';
    public int $timeout = 240;
    public int $tries = 5;

    private const MATCHES_COLLECTION = 'matches';

    private ?FirestoreClient $firestore = null;
    private RecentMatchStatusLogger $logger;
    private array $firestoreSettings = [];

    private int $updated = 0;
    private int $skipped = 0;

    public function __construct(
        private readonly ?string $runId = null,
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
        $this->logger = new RecentMatchStatusLogger($this->runId);

        $this->log('job_started', 'info', 'MoveEndedMatchesToRecent job started', []);

        $settingsService = app(AdminSettingsService::class);

        if (!$settingsService->isCronEnabled(self::CRON_KEY)) {
            $this->log('job_disabled', 'warning', 'Recent matches job paused via emergency controls.');
            return;
        }

        $this->firestoreSettings = $settingsService->firestoreSettings();

        try {
            $this->firestore = $this->initializeClients();
            $this->log('initialize_clients', 'success', 'Firestore client initialised');
        } catch (Throwable $e) {
            $this->log('initialize_clients', 'error', 'Failed to initialise Firestore client', $this->exceptionContext($e));

            throw $e;
        }

        $matches = $this->fetchExpiredMatches();

        if (empty($matches)) {
            $this->log('no_matches', 'info', 'No matches require status update.');
            $this->finalize('info');
            return;
        }

        foreach ($matches as $snapshot) {
            $this->processMatch($snapshot);
        }

        $status = $this->updated > 0 ? 'success' : 'info';
        $this->finalize($status);
    }

    private function processMatch(DocumentSnapshot $snapshot): void
    {
        $matchId = (string) $snapshot->id();
        $data = $snapshot->data();

        try {
            $this->firestore
                ->collection(self::MATCHES_COLLECTION)
                ->document($matchId)
                ->set([
                    'updatedAt' => now()->valueOf(),
                    'matchInfo' => [
                        'state' => 'Complete',
                        'state_lowercase' => 'complete',
                        'status' => NULL,
                    ],
                ], ['merge' => true]);

            $this->updated++;
            $this->log('match_updated', 'success', 'Match moved to recent state', [
                'match_id' => $matchId,
            ]);
        } catch (Throwable $e) {
            $this->skipped++;
            $this->log('match_update_failed', 'error', 'Failed to update match state', $this->exceptionContext($e, [
                'match_id' => $matchId,
                'payload' => $data,
                'message' => $e->getMessage(),
            ]));
        }
    }

    /**
     * @return DocumentSnapshot[]
     */
    private function fetchExpiredMatches(): array
    {
        $cutoff = now()->copy()->addMinutes(10)->valueOf();

        try {
            $query = $this->firestore
                ->collection(self::MATCHES_COLLECTION)
                ->where('matchInfo.enddate', '<=', $cutoff)
                ->limit(200);

            $documents = $query->documents();
        } catch (Throwable $e) {
            $this->log('match_lookup_failed', 'error', 'Failed to query matches for status update', $this->exceptionContext($e));

            return [];
        }

        $filtered = [];

        foreach ($documents as $snapshot) {
            if (!$snapshot->exists()) {
                continue;
            }

            $data = $snapshot->data();
            $stateLower = strtolower((string) (data_get($data, 'matchInfo.state_lowercase') ?? ''));

            if ($stateLower === 'complete') {
                continue;
            }

            $filtered[] = $snapshot;
        }

        $this->log('match_candidates_resolved', 'info', 'Resolved matches eligible for recent status update', [
            'count' => count($filtered),
        ]);

        return $filtered;
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

        return new FirestoreClient($options);
    }

    private function finalize(string $status): void
    {
        $apiSummary = $this->getApiCallBreakdown();

        $this->log('job_completed', $status, 'MoveEndedMatchesToRecent job finished', [
            'updated' => $this->updated,
            'skipped' => $this->skipped,
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

    private function exceptionContext(Throwable $e, array $context = []): array
    {
        return array_merge($context, [
            'exception' => get_class($e),
            'message' => $e->getMessage(),
        ]);
    }
}
