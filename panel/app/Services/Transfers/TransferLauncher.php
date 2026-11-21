<?php

namespace App\Services\Transfers;

use App\Models\Transfer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\PhpExecutableFinder;

/**
 * Starts transfers. Each one is `php artisan johnny:transfer <id>`, detached from the web
 * request with nohup so it outlives it; only a few run at once, the rest wait as "pending" and
 * are started when a slot frees up (a finished transfer and the Transfers page both call drain()).
 */
class TransferLauncher
{
    /** A started process that has not claimed its transfer after this long is started again. */
    private const RELAUNCH_AFTER_SECONDS = 60;

    /**
     * Mark dead transfers as failed, then start waiting ones while slots are free.
     */
    public function drain(): void
    {
        $this->reapStale();

        $limit = max(1, (int) config('services.johnny.transfers.concurrency', 2));
        $relaunchBefore = now()->subSeconds(self::RELAUNCH_AFTER_SECONDS);

        $busy = Transfer::query()->where('status', Transfer::RUNNING)->count()
            + Transfer::query()->where('status', Transfer::PENDING)->where('launched_at', '>=', $relaunchBefore)->count();

        if ($busy >= $limit) {
            return;
        }

        $waiting = Transfer::query()
            ->where('status', Transfer::PENDING)
            ->where(fn ($query) => $query->whereNull('launched_at')->orWhere('launched_at', '<', $relaunchBefore))
            ->orderBy('id')
            ->limit($limit - $busy)
            ->get();

        foreach ($waiting as $transfer) {
            $this->launch($transfer);
        }
    }

    public function launch(Transfer $transfer): void
    {
        $transfer->forceFill(['launched_at' => now()])->save();

        $result = Process::path(base_path())->run($this->command($transfer));

        if (! $result->successful()) {
            $transfer->forceFill([
                'status' => Transfer::FAILED,
                'message' => 'The transfer process could not be started: '.(trim($result->errorOutput()) ?: 'exit code '.$result->exitCode()).'.',
                'finished_at' => now(),
            ])->save();
        }
    }

    /**
     * `sh -c 'nohup php artisan johnny:transfer 12 >/dev/null 2>&1 </dev/null &'`: the shell
     * returns at once and the runner keeps going on its own.
     *
     * @return list<string>
     */
    public function command(Transfer $transfer): array
    {
        $php = (string) (config('services.johnny.transfers.php') ?: (new PhpExecutableFinder)->find(false) ?: 'php');

        return ['sh', '-c', sprintf(
            'nohup %s %s johnny:transfer %d >/dev/null 2>&1 </dev/null &',
            escapeshellarg($php),
            escapeshellarg(base_path('artisan')),
            $transfer->id,
        )];
    }

    /**
     * A running transfer reports every few seconds. One that has been silent for minutes lost its
     * process (PHP-FPM or the server restarted): say so instead of showing it running forever.
     */
    public function reapStale(): void
    {
        Transfer::query()
            ->where('status', Transfer::RUNNING)
            ->where(fn ($query) => $query->whereNull('heartbeat_at')->orWhere('heartbeat_at', '<', now()->subSeconds(Transfer::STALE_AFTER_SECONDS)))
            ->where('started_at', '<', now()->subSeconds(Transfer::STALE_AFTER_SECONDS))
            ->get()
            ->each(fn (Transfer $transfer) => $transfer->forceFill([
                'status' => Transfer::FAILED,
                'message' => 'The transfer stopped without finishing (PHP-FPM or the server was restarted). Run it again: files already copied are skipped.',
                'finished_at' => now(),
            ])->save());
    }

    /**
     * Whether the rclone binary answers; remembered for a few minutes.
     */
    public function rcloneAvailable(): bool
    {
        return (bool) Cache::remember('johnny.rclone.available', 300, function () {
            try {
                return Process::timeout(10)->run([(string) config('services.johnny.transfers.rclone', 'rclone'), 'version'])->successful();
            } catch (\Throwable) {
                return false;
            }
        });
    }
}
