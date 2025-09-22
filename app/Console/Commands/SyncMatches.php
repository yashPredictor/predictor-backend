<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SyncMatches extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:sync-matches';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        Http::get("https://9000-firebase-studio-1754595503757.cluster-wurh6gchdjcjmwrw2tqtufvhss.cloudworkstations.dev/api/cron/sync-commentary")->json();
    }
}
