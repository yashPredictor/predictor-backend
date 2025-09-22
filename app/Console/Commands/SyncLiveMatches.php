<?php

namespace App\Console\Commands;

use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class SyncLiveMatches extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'matches:sync-live-firestore';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Syncs live match details from Cricbuzz API to Google Firestore.';
    protected $firestore;
    
    public function __construct()
    {
        parent::__construct();

        try {
            $this->firestore = new FirestoreClient([
                'projectId' => config('services.firestore.project_id'),
            ]);
        } catch (\Exception $e) {
            Log::emergency('Could not connect to Firestore: ' . $e->getMessage());
            $this->error('Could not connect to Firestore. Check your configuration and credentials.');
            exit(1);
        }
    }

    public function handle()
    {
        Log::info('Cron Job (Laravel): Starting live matches sync to Firestore...');
        $this->info('Starting live matches sync to Firestore...');

        $liveStates = [
            "live", "inprogress", "stumps", "lunch", "drinks", 
            "innings break", "in progress", "delay", "toss delay"
        ];
        
        $matchesCollection = $this->firestore->collection('matches');
        
        // Firestore से लाइव मैचों की क्वेरी करें
        $query = $matchesCollection->where('matchInfo.state_lowercase', 'in', $liveStates);
        $documents = $query->documents();

        $matchesToSync = iterator_to_array($documents);

        if (empty($matchesToSync)) {
            Log::info('Cron Job (Laravel): No live matches to update in Firestore.');
            $this->info('No live matches to update.');
            return 0;
        }

        $count = count($matchesToSync);

        $this->info("Found {$count} live matches to update.");

        $apiKey = config('services.cricbuzz.key');
        $apiHost = 'cricbuzz-cricket2.p.rapidapi.com';

        foreach ($matchesToSync as $document) {
            $matchData = $document->data();
            $matchId = $matchData['matchInfo']['matchId'] ?? null;

            if (!$matchId) {
                Log::warning('Skipping document without a matchId.', ['document_id' => $document->id()]);
                continue;
            }

            try {
                $response = Http::withHeaders([
                    'x-rapidapi-host' => $apiHost,
                    'x-rapidapi-key' => $apiKey,
                ])->get("https://{$apiHost}/mcenter/v1/{$matchId}");

                if ($response->successful()) {
                    $apiData = $response->json();

                    if ($apiData && !empty($apiData)) {
                        $matchDocRef = $matchesCollection->document($matchId);
                        
                        $finalData = array_merge($matchData, $apiData);
                        
                        $matchDocRef->set($finalData, ['merge' => true]);

                        Log::info("Successfully updated details for live match {$matchId} in Firestore.");
                        $this->info("Updated match in Firestore: {$matchId}");
                    } else {
                         Log::warning("Received empty response from API for match {$matchId}.");
                    }
                } else {
                    Log::error("Failed to fetch details for match {$matchId}. Status: " . $response->status());
                }

            } catch (\Exception $e) {
                Log::error("Exception while syncing match {$matchId}: " . $e->getMessage());
            }
        }

        Log::info("Cron Job (Laravel): Finished live matches sync to Firestore.");
        $this->info('Live matches sync finished.');
        return 0;
    }

}
