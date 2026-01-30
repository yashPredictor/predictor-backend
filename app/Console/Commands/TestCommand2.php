<?php

namespace App\Console\Commands;

use App\Jobs\SyncAdvancedMatches;
use App\Services\AdminSettingsService;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class TestCommand2 extends Command
{
    protected $signature = 'app:player-advanced-matches';
    protected $description = '';
    private ?FirestoreClient $firestore = null;
    private ?string $apiKey = null;
    private string $apiHost;
    private string $baseUrl = 'http://139.59.8.120:8900';
    private array $firestoreSettings = [];
    private array $cricbuzzSettings = [];

    public function handle(): void
    {
        $settingsService = app(AdminSettingsService::class);

        $this->firestoreSettings = $settingsService->firestoreSettings();
        $this->cricbuzzSettings = $settingsService->cricbuzzSettings();

        $this->apiHost = $this->cricbuzzSettings['host'] ?? $this->apiHost;
        $this->baseUrl = sprintf('http://%s', rtrim($this->apiHost, '/'));

        try {
            $this->firestore = $this->initializeClients();
            $this->info('Firestore client initialised');
        } catch (Throwable $e) {
            $this->error('Failed to initialise Firestore client');
            throw $e;
        }

        $batchSize = 100; // Adjust based on your memory limits
        $lastDocument = null;

        do {
            $query = $this->firestore->collection('playerAdvancedMatches')->limit($batchSize);

            if ($lastDocument) {
                $query = $query->startAfter($lastDocument);
            }

            $documents = $query->documents();

            if ($documents->isEmpty()) {
                break;
            }

            foreach ($documents as $doc) {
                $data = $doc->data();
                $mids = $this->extractAllMids($data);

                SyncAdvancedMatches::dispatch($mids)->onQueue('advanced-details');
            }

            // Set the last document for the next page
            $lastDocument = $documents->rows()[count($documents->rows()) - 1];

        } while (true);
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
                'Firestore project id missing. Set FIRESTORE_PROJECT_ID or provide a service account JSON with project_id.'
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

    public function extractAllMids(array $array): array
    {
        $mids = [];

        array_walk_recursive($array, function ($value, $key) use (&$mids) {
            if ($key === 'mid') {
                $mids[] = $value;
            }
        });

        return $mids;
    }

}
