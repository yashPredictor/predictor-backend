<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Google\Cloud\Firestore\FirestoreClient;

class SyncSeriesData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:sync-series';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetches all series and their matches from Cricbuzz API and stores them in Firestore.';

    /**
     * @var FirestoreClient|null
     */
    protected $firestore;

    /**
     * @var string|null
     */
    protected $apiKey;

    /**
     * @var string
     */
    protected $apiHost = 'cricbuzz-cricket2.p.rapidapi.com';

    /**
     * The base URL for the series API.
     *
     * @var string
     */
    protected const API_BASE_URL_SERIES = 'https://cricbuzz-cricket2.p.rapidapi.com/series/v1/';

    /**
     * Initializes Firestore client and API key within the handle method.
     *
     * @return bool
     */
    private function initializeClients(): bool
    {
        try {
            $this->firestore = new FirestoreClient([
                'projectId' => config('services.firestore.project_id'),
            ]);
            $this->apiKey = config('services.cricbuzz.key');

            if (!$this->apiKey) {
                throw new \Exception("Cricbuzz API key is not configured.");
            }

            return true;
        } catch (\Exception $e) {
            Log::emergency('Initialization failed: ' . $e->getMessage());
            $this->error('Initialization failed. Check your configuration and credentials.');
            return false;
        }
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        if (!$this->initializeClients()) {
            return 1; // Exit with an error code
        }

        Log::info('SYNC-SERIES: Starting series data sync...');
        $this->info('Starting series data sync...');

        $seriesTypes = ['international', 'league', 'domestic', 'women'];
        $batch = $this->firestore->batch();
        $operations = 0;
        $totalSeries = 0;

        foreach ($seriesTypes as $type) {
            $this->line("Fetching '{$type}' series...");
            try {
                $response = Http::withHeaders([
                    'x-rapidapi-host' => $this->apiHost,
                    'x-rapidapi-key' => $this->apiKey,
                ])->get(self::API_BASE_URL_SERIES . $type);

                if ($response->successful() && isset($response->json()['seriesMapProto'])) {
                    foreach ($response->json()['seriesMapProto'] as $monthData) {
                        if (isset($monthData['series'])) {
                            foreach ($monthData['series'] as $series) {
                                $seriesRef = $this->firestore->collection('series')->document($series['id']);
                                $seriesWithCategory = array_merge($series, [
                                    'category' => $type,
                                    'startDtTimestamp' => (int)$series['startDt'],
                                    'endDtTimestamp' => (int)$series['endDt'],
                                ]);
                                $batch->set($seriesRef, $seriesWithCategory, ['merge' => true]);
                                $operations++;
                                $totalSeries++;

                                if ($operations >= 490) {
                                    $batch->commit();
                                    $batch = $this->firestore->batch();
                                    $operations = 0;
                                }
                            }
                        }
                    }
                } else {
                    Log::error("Failed to fetch series for type: {$type}. Status: " . $response->status());
                }
            } catch (\Exception $e) {
                Log::error("Exception while fetching series type '{$type}': " . $e->getMessage());
            }
        }

        if ($operations > 0) {
            $batch->commit();
        }

        $metadataRef = $this->firestore->collection('seriesMetadata')->document('seriesMetadata');
        $metadataRef->set(['lastFetched' => now()->getTimestamp() * 1000]);

        $this->info("Successfully synced a total of {$totalSeries} series.");
        Log::info("SYNC-SERIES: Finished syncing series data.");

        $this->syncAllMatches();

        return 0;
    }

    /**
     * Fetches and stores matches for all series found in Firestore.
     */
    private function syncAllMatches()
    {
        Log::info('SYNC-MATCHES: Starting to sync matches for all series...');
        $this->info('Starting to sync matches for all series...');

        $seriesDocs = $this->firestore->collection('series')->documents();
        $batch = $this->firestore->batch();
        $operations = 0;
        $totalMatches = 0;

        foreach ($seriesDocs as $seriesDoc) {
            if (!$seriesDoc->exists()) continue;

            $seriesData = $seriesDoc->data();
            $seriesId = $seriesData['id'];

            $this->line("Fetching matches for series ID: {$seriesId}");

            try {
                $response = Http::withHeaders([
                    'x-rapidapi-host' => $this->apiHost,
                    'x-rapidapi-key' => $this->apiKey,
                ])->get(self::API_BASE_URL_SERIES . $seriesId);

                if ($response->successful() && isset($response->json()['matchDetails'])) {
                    foreach ($response->json()['matchDetails'] as $detail) {
                        if (isset($detail['matchDetailsMap']['match'])) {
                            foreach ($detail['matchDetailsMap']['match'] as $match) {
                                $matchInfo = $match['matchInfo'];
                                $matchId = $matchInfo['matchId'];
                                $matchRef = $this->firestore->collection('matches')->document((string)$matchId);

                                $matchWithMeta = array_merge($match, [
                                    'category' => $seriesData['category'],
                                    'seriesId' => (int)$seriesId,
                                    'matchInfo' => array_merge($matchInfo, [
                                        'state_lowercase' => strtolower($matchInfo['state']),
                                        'startDate' => (int)$matchInfo['startDate'],
                                        'endDate' => isset($matchInfo['endDate']) ? (int)$matchInfo['endDate'] : (int)$matchInfo['startDate'],
                                    ])
                                ]);

                                $batch->set($matchRef, $matchWithMeta, ['merge' => true]);
                                $operations++;
                                $totalMatches++;

                                if ($operations >= 490) {
                                    $batch->commit();
                                    $batch = $this->firestore->batch();
                                    $operations = 0;
                                }
                            }
                        }
                    }
                }
            } catch (\Exception $e) {
                Log::error("Failed to sync matches for series {$seriesId}: " . $e->getMessage());
            }
        }

        if ($operations > 0) {
            $batch->commit();
        }

        Log::info("SYNC-MATCHES: Finished syncing {$totalMatches} matches.");
        $this->info("Finished syncing all matches.");
    }
}