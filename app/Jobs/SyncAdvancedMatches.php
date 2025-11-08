<?php

namespace App\Jobs;

use App\Services\AdminSettingsService;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncAdvancedMatches implements ShouldQueue
{
    use Queueable;

    public $mids = [];
    private ?FirestoreClient $firestore = null;
    private array $firestoreSettings = [];
    private ?string $apiKey = null;
    private string $apiHost;
    public int $timeout = 0;

    public function __construct($mids)
    {
        $this->mids = $mids;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $settingsService = app(AdminSettingsService::class);

        $this->firestoreSettings = $settingsService->firestoreSettings();

        try {
            $this->firestore = $this->initializeClients();
            Log::info('Firestore client initialised');
        } catch (Throwable $e) {
            Log::error('Failed to initialise Firestore client');
            throw $e;
        }
        
        foreach ($this->mids as $id) {
            Log::info('Processing mid: ' . $id);

            $url = sprintf('http://139.59.8.120:8900/matches/%s/advance', $id);
            $match_points_url = sprintf('http://139.59.8.120:8900/matches/%s/newpoint2', $id);
            $match_squads_url = sprintf('http://139.59.8.120:8900/matches/%s/squads', $id);

            $response = Http::withHeaders([
                'x-auth-user' => '17ca7cb0909e48559ae2906d533d0e9c',
                'no-cache' => 'true',
            ])->get($url);

            $updatedResponse = [];
            if ($response->successful()) {
                $data = $response->json();
                $updatedResponse['details'] = $data['response'] ?? [];
                $updatedResponse['etag'] = $data['etag'] ?? null;
                $updatedResponse['modified'] = $data['modified'] ?? null;
                $updatedResponse['datetime'] = $data['datetime'] ?? null;
                $updatedResponse['api_version'] = $data['api_version'] ?? null;

                $updateData = $this->firestore->collection('advancedMatchDetails')->document($id);
                $updateData->set($updatedResponse);
            } else {
                Log::error('Failed to sync advanced match data for mid: ' . $id . '. Status: ' . $response->status());
            }


            $response = Http::withHeaders([
                'x-auth-user' => '17ca7cb0909e48559ae2906d533d0e9c',
                'no-cache' => 'true',
            ])->get($match_points_url);

            $updatedResponse = [];
            if ($response->successful()) {
                $data = $response->json();
                $updatedResponse['points'] = $data['response'] ?? [];
                $updatedResponse['etag'] = $data['etag'] ?? null;
                $updatedResponse['modified'] = $data['modified'] ?? null;
                $updatedResponse['datetime'] = $data['datetime'] ?? null;
                $updatedResponse['api_version'] = $data['api_version'] ?? null;

                $updateData = $this->firestore->collection('advancedMatchPoints')->document($id);
                $updateData->set($updatedResponse);
            } else {
                Log::error('Failed to sync advanced match data for mid: ' . $id . '. Status: ' . $response->status());
            }

            $response = Http::withHeaders([
                'x-auth-user' => '17ca7cb0909e48559ae2906d533d0e9c',
                'no-cache' => 'true',
            ])->get($match_squads_url);

            $updatedResponse = [];
            if ($response->successful()) {
                $data = $response->json();
                $updatedResponse['squads'] = $data['response'] ?? [];
                $updatedResponse['etag'] = $data['etag'] ?? null;
                $updatedResponse['modified'] = $data['modified'] ?? null;
                $updatedResponse['datetime'] = $data['datetime'] ?? null;
                $updatedResponse['api_version'] = $data['api_version'] ?? null;

                $updateData = $this->firestore->collection('advancedMatchSquads')->document($id);
                $updateData->set($updatedResponse);
            } else {
                Log::error('Failed to sync advanced match data for mid: ' . $id . '. Status: ' . $response->status());
            }
        }
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
}
