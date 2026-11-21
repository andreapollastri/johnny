<?php

namespace App\Console\Commands;

use App\Services\Transfers\SyncScheduler;
use App\Services\Transfers\TransferLauncher;
use Illuminate\Console\Command;

class RunSyncs extends Command
{
    protected $signature = 'johnny:syncs:run';

    protected $description = 'Start the scheduled syncs that are due (run every minute by the scheduler).';

    public function handle(SyncScheduler $scheduler, TransferLauncher $launcher): int
    {
        $scheduler->beat();

        $started = $scheduler->runDue();

        // Also moves waiting transfers on when nobody has the panel open.
        $launcher->drain();

        $this->info($started === 0 ? 'No sync is due.' : "Started {$started} sync run(s).");

        return self::SUCCESS;
    }
}
