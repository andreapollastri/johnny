<?php

namespace App\Services\Transfers;

use App\Models\Sync;
use App\Models\Transfer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Starts the runs of scheduled syncs. `johnny:syncs:run` calls it every minute from cron; the
 * Transfers pages call it too, so syncs still move on a machine where cron is not set up.
 */
class SyncScheduler
{
    /** Cache key: when cron last ran the scheduler, to tell the user if it does not. */
    public const HEARTBEAT = 'johnny.syncs.heartbeat';

    /** Without a heartbeat for this long, the scheduler is considered not running. */
    public const HEARTBEAT_STALE_SECONDS = 180;

    public function __construct(
        private TransferLauncher $launcher
    ) {}

    /**
     * Start a run for every sync whose time has come.
     *
     * @return int How many runs were started.
     */
    public function runDue(): int
    {
        $started = 0;

        $due = Sync::query()
            ->where('enabled', true)
            ->where(fn ($query) => $query->whereNull('next_run_at')->orWhere('next_run_at', '<=', now()))
            ->orderBy('next_run_at')
            ->get();

        foreach ($due as $sync) {
            if ($this->start($sync) !== null) {
                $started++;
            }
        }

        if ($started > 0) {
            $this->launcher->drain();
        }

        return $started;
    }

    /**
     * Queue one run of a sync. Returns null while its previous run is still going: runs of the
     * same sync never overlap, the next one starts as soon as the current one ends. The first
     * run of a sync out may create the bucket at the provider.
     */
    public function start(Sync $sync, bool $createBucket = false): ?Transfer
    {
        if ($sync->runs()->whereIn('status', [Transfer::PENDING, Transfer::RUNNING])->exists()) {
            return null;
        }

        $connection = $sync->remoteConnection;

        if ($connection === null) {
            $sync->forceFill(['enabled' => false])->save();

            return null;
        }

        $run = Transfer::query()->create([
            'sync_id' => $sync->id,
            'remote_connection_id' => $connection->id,
            'user_id' => $sync->user_id,
            'connection_name' => $connection->name,
            'provider' => $connection->provider,
            'direction' => $sync->direction,
            'mode' => $sync->mode,
            'local_bucket' => $sync->local_bucket,
            'remote_bucket' => $sync->remote_bucket,
            'prefix' => $sync->prefix,
            'dry_run' => false,
            'create_bucket' => $createBucket && ! $sync->isImport(),
            'status' => Transfer::PENDING,
        ]);

        $sync->forceFill([
            'last_run_at' => now(),
            'next_run_at' => $this->nextRun($sync),
        ])->save();

        $this->prune($sync);

        return $run;
    }

    /**
     * When the run after this one is due. Counted from the time this run was planned for, not
     * from the second it started, and on the minute: cron ticks on the minute, and a sync due at
     * 12:01:02 would be skipped by the 12:01:00 tick, halving an every-minute schedule.
     */
    private function nextRun(Sync $sync): Carbon
    {
        $planned = $sync->next_run_at !== null && $sync->next_run_at->isPast() ? $sync->next_run_at : now();
        $next = $planned->copy()->addMinutes($sync->interval_minutes)->startOfMinute();

        // Late by more than one interval (the previous run took long, the server was off): the
        // missed runs are not made up for.
        return $next->isPast() ? now()->addMinutes($sync->interval_minutes)->startOfMinute() : $next;
    }

    /**
     * Keep the latest finished runs of a sync and drop the older ones.
     */
    public function prune(Sync $sync): void
    {
        $keep = $sync->runs()->latest('id')->limit(Sync::KEEP_RUNS)->pluck('id');

        $sync->runs()
            ->whereNotIn('id', $keep)
            ->whereNotIn('status', [Transfer::PENDING, Transfer::RUNNING])
            ->delete();
    }

    public function beat(): void
    {
        Cache::put(self::HEARTBEAT, now()->getTimestamp(), 86400);
    }

    /**
     * Whether cron ran the scheduler in the last few minutes.
     */
    public function isRunning(): bool
    {
        $beat = Cache::get(self::HEARTBEAT);

        return is_numeric($beat) && (int) $beat >= now()->getTimestamp() - self::HEARTBEAT_STALE_SECONDS;
    }
}
