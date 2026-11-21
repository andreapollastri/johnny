<?php

namespace App\Console\Commands;

use App\Services\GlobalKeys;
use Illuminate\Console\Command;

class ApplyGlobalKeys extends Command
{
    protected $signature = 'johnny:keys:apply {--all : Grant on every bucket again, not only on new ones}';

    protected $description = 'Grant the access keys that reach all buckets on buckets created outside the panel.';

    public function handle(GlobalKeys $keys): int
    {
        $granted = $keys->reconcile((bool) $this->option('all'));

        $this->info($granted === 0
            ? 'Nothing to do: no global keys, or no new buckets.'
            : "Global keys granted on {$granted} bucket(s).");

        return self::SUCCESS;
    }
}
