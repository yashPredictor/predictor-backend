<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Throwable;

class SyncMatchesLoop extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:sync-matches-loop {--interval=10 : Seconds to wait between runs}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Continuously trigger app:sync-matches on a fixed interval';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $interval = (int) $this->option('interval');

        if ($interval < 1) {
            $this->error('Interval must be at least 1 second.');

            return self::FAILURE;
        }

        $this->info(sprintf('Starting loop; running every %d seconds. Press Ctrl+C to stop.', $interval));
        $iteration = 1;

        while (true) {
            // Loop forever until the process receives an interrupt signal.
            $this->info(sprintf('[%s] Iteration %d', now()->format('Y-m-d H:i:s'), $iteration));

            try {
                $exitCode = $this->call('app:sync-matches');
            } catch (Throwable $exception) {
                $this->error(sprintf('app:sync-matches threw an exception: %s', $exception->getMessage()));
                $this->line('Retrying after the interval...');
                sleep($interval);

                continue;
            }

            if ($exitCode !== self::SUCCESS) {
                $this->warn(sprintf('app:sync-matches exited with code %d', $exitCode));
            }

            $iteration++;
            sleep($interval);
        }

        return self::SUCCESS;
    }
}
