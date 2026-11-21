<?php

namespace App\Console\Commands;

use App\Models\Transfer;
use App\Services\Transfers\TransferRunner;
use Illuminate\Console\Command;

class RunTransfer extends Command
{
    protected $signature = 'johnny:transfer {transfer : The id of a pending transfer}';

    protected $description = 'Run one import or export to its end (started by the panel; one process per transfer).';

    public function handle(TransferRunner $runner): int
    {
        $transfer = Transfer::query()->find($this->argument('transfer'));

        if ($transfer === null) {
            $this->error('Transfer not found.');

            return self::FAILURE;
        }

        if ($transfer->status !== Transfer::PENDING) {
            $this->warn("Transfer {$transfer->id} is {$transfer->status}: nothing to run.");

            return self::SUCCESS;
        }

        $runner->run($transfer);
        $this->info("Transfer {$transfer->id} {$transfer->status}: {$transfer->message}");

        return $transfer->status === Transfer::FAILED ? self::FAILURE : self::SUCCESS;
    }
}
