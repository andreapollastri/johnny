<?php

namespace App\Services\Transfers;

use App\Http\Controllers\DashboardController;
use App\Models\Transfer;
use App\Support\Format;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;

/**
 * Runs one transfer to its end: starts rclone, follows its output, keeps the row up to date
 * (progress, log, a heartbeat) and stops rclone when the panel asks for a cancellation.
 * Run by `php artisan johnny:transfer <id>`, one process per transfer.
 */
class TransferRunner
{
    /** Pause between two reads of rclone's output, in microseconds. */
    public int $tick = 500000;

    /** Seconds between two writes of the progress (and checks for a cancellation). */
    public float $saveEvery = 2.0;

    public function __construct(private TransferLauncher $launcher) {}

    public function run(Transfer $transfer): void
    {
        if (! $this->claim($transfer)) {
            return;
        }

        try {
            $this->transfer($transfer);
        } catch (\Throwable $e) {
            $this->finish($transfer, Transfer::FAILED, $e->getMessage());
        } finally {
            Cache::forget(DashboardController::CACHE_KEY);
            $this->launcher->drain();
        }
    }

    /**
     * Only one process may run a transfer: the claim is a single UPDATE on the pending row.
     */
    private function claim(Transfer $transfer): bool
    {
        $claimed = Transfer::query()
            ->whereKey($transfer->getKey())
            ->where('status', Transfer::PENDING)
            ->update([
                'status' => Transfer::RUNNING,
                'pid' => getmypid() ?: null,
                'started_at' => now(),
                'heartbeat_at' => now(),
                'message' => null,
            ]);

        $transfer->refresh();

        return $claimed === 1;
    }

    private function transfer(Transfer $transfer): void
    {
        $connection = $transfer->remoteConnection;
        if ($connection === null) {
            $this->finish($transfer, Transfer::FAILED, 'The connection this transfer used was deleted.');

            return;
        }

        $command = RcloneCommand::forTransfer($transfer, $connection);
        $progress = new RcloneProgress;
        $process = Process::forever()->env($command['env'])->start($command['argv']);

        $cancelled = false;
        $savedAt = 0.0;

        while ($process->running()) {
            $this->read($process, $progress);

            if (microtime(true) - $savedAt >= $this->saveEvery) {
                $this->save($transfer, $progress);
                $savedAt = microtime(true);

                if (! $cancelled && $this->cancellationRequested($transfer)) {
                    $cancelled = true;
                    $process->signal(15);
                }
            }

            if ($this->tick > 0) {
                usleep($this->tick);
            }
        }

        $result = $process->wait();
        $this->read($process, $progress);
        $progress->finish();
        $this->save($transfer, $progress);

        if ($cancelled) {
            $this->finish($transfer, Transfer::CANCELLED, 'Cancelled. '.$this->summary($transfer, $progress));
        } elseif ($result->successful()) {
            $this->finish($transfer, Transfer::COMPLETED, $this->summary($transfer, $progress));
        } else {
            $this->finish($transfer, Transfer::FAILED, $this->failure($result->exitCode(), $progress));
        }
    }

    private function failure(?int $exitCode, RcloneProgress $progress): string
    {
        if ($exitCode === 127) {
            return 'rclone was not found on this server. Install it with `sudo bash scripts/install-rclone.sh`, or set JOHNNY_RCLONE_BINARY in panel/.env.';
        }

        $log = $progress->log();

        return $progress->lastError ?? ($log !== [] ? (string) end($log) : "rclone stopped with exit code {$exitCode}.");
    }

    /**
     * Everything rclone wrote since the last read (it logs to stderr).
     */
    private function read(InvokedProcess $process, RcloneProgress $progress): void
    {
        for ($i = 0; $i < 10000; $i++) {
            $error = $process->latestErrorOutput();
            $output = $process->latestOutput();

            if ($error === '' && $output === '') {
                return;
            }

            $progress->feed($error);
            $progress->feed($output);
        }
    }

    private function save(Transfer $transfer, RcloneProgress $progress): void
    {
        $transfer->forceFill([
            'bytes_done' => $progress->bytes,
            'bytes_total' => max($progress->totalBytes, $progress->bytes),
            'files_done' => $progress->transfers,
            'files_total' => max($progress->totalTransfers, $progress->transfers),
            'checks_done' => $progress->checks,
            'errors' => $progress->errors,
            'speed' => $progress->speed,
            'eta' => $progress->eta,
            'log' => $progress->log(),
            'heartbeat_at' => now(),
        ])->save();
    }

    private function cancellationRequested(Transfer $transfer): bool
    {
        return Transfer::query()->whereKey($transfer->getKey())->whereNotNull('cancel_requested_at')->exists();
    }

    private function finish(Transfer $transfer, string $status, string $message): void
    {
        $transfer->forceFill([
            'status' => $status,
            'message' => mb_strimwidth($message, 0, 1000, '…'),
            'eta' => null,
            'speed' => 0,
            'pid' => null,
            'finished_at' => now(),
        ])->save();
    }

    /**
     * "Copied 1,300 files (3.50 GB)." — or what a dry run found.
     */
    private function summary(Transfer $transfer, RcloneProgress $progress): string
    {
        if ($transfer->dry_run) {
            return 'Dry run: nothing was changed. The log lists what a real run would do.';
        }

        $files = number_format($progress->transfers).' '.($progress->transfers === 1 ? 'file' : 'files');
        $summary = $progress->transfers === 0
            ? 'Nothing to copy: the destination already has every file.'
            : "Copied {$files} (".Format::bytes($progress->bytes).').';

        if ($progress->deletes > 0) {
            $summary .= ' Deleted '.number_format($progress->deletes).' at the destination.';
        }

        return $summary;
    }
}
