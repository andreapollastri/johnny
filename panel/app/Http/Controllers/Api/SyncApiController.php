<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RemoteConnection;
use App\Models\Sync;
use App\Models\Transfer;
use App\Services\Transfers\SyncScheduler;
use App\Services\Transfers\TransferLauncher;
use App\Services\Transfers\TransferPlanner;
use App\Support\Intervals;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Scheduled syncs: a transfer that repeats every `interval_minutes`, in or out.
 */
class SyncApiController extends Controller
{
    public function __construct(
        private TransferPlanner $planner,
        private SyncScheduler $scheduler,
        private TransferLauncher $launcher,
    ) {}

    public function index(): JsonResponse
    {
        $syncs = Sync::query()->with(['remoteConnection', 'latestRun'])->orderBy('id')->get();

        return response()->json([
            'data' => $syncs->map(fn (Sync $sync) => $sync->toApi())->values(),
            'scheduler_running' => $this->scheduler->isRunning(),
        ]);
    }

    /**
     * Create a sync and start its first run. The interval is in minutes: 1 is every minute,
     * 1440 every day.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate(TransferPlanner::rules() + [
            'interval_minutes' => ['required', 'integer', 'min:1', 'max:'.Intervals::MAX_MINUTES],
        ], TransferPlanner::messages());

        $connection = RemoteConnection::query()->findOrFail($validated['connection_id']);
        $validated['remote_bucket'] = $this->planner->remoteLocation($connection, $validated['remote_bucket']);
        $this->planner->prepareLocalBucket($validated['direction'], $validated['local_bucket']);

        $sync = $this->planner->sync($connection, $validated, (int) $validated['interval_minutes'], $request->user()?->getAuthIdentifier());

        return response()->json(['data' => $this->withRuns($sync)], 201);
    }

    public function show(Sync $sync): JsonResponse
    {
        return response()->json(['data' => $this->withRuns($sync)]);
    }

    /**
     * Change the interval or the mode, pause (`enabled: false`) or resume. A resumed sync runs
     * right away.
     */
    public function update(Request $request, Sync $sync): JsonResponse
    {
        $validated = $request->validate([
            'interval_minutes' => ['sometimes', 'integer', 'min:1', 'max:'.Intervals::MAX_MINUTES],
            'mode' => ['sometimes', Rule::in([Transfer::COPY, Transfer::SYNC])],
            'enabled' => ['sometimes', 'boolean'],
        ]);

        $changes = [];

        if (isset($validated['mode'])) {
            $changes['mode'] = $validated['mode'];
        }

        if (isset($validated['interval_minutes'])) {
            $next = ($sync->last_run_at ?? now())->copy()->addMinutes((int) $validated['interval_minutes'])->startOfMinute();
            $changes['interval_minutes'] = (int) $validated['interval_minutes'];
            $changes['next_run_at'] = $next->isPast() ? now() : $next;
        }

        if (array_key_exists('enabled', $validated)) {
            $resumed = (bool) $validated['enabled'] && ! $sync->enabled;
            $changes['enabled'] = (bool) $validated['enabled'];

            if ($resumed) {
                $changes['next_run_at'] = now();
            }
        }

        $sync->forceFill($changes)->save();

        if ($sync->isDue()) {
            $this->scheduler->runDue();
        }

        return response()->json(['data' => $this->withRuns($sync)]);
    }

    /**
     * Run the sync now, without waiting for its next time.
     */
    public function run(Sync $sync): JsonResponse
    {
        $run = $this->scheduler->start($sync);

        if ($run === null) {
            return response()->json(['message' => 'A run of this sync is still in progress.'], 409);
        }

        $this->launcher->drain();

        return response()->json(['data' => $run->refresh()->toApi()], 201);
    }

    public function destroy(Sync $sync): JsonResponse|Response
    {
        if ($sync->runs()->active()->exists()) {
            return response()->json(['message' => 'A run of this sync is in progress. Cancel it or wait for it to finish.'], 409);
        }

        $sync->runs()->delete();
        $sync->delete();

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function withRuns(Sync $sync): array
    {
        $sync = $sync->refresh()->load(['remoteConnection', 'latestRun']);

        return $sync->toApi() + [
            'runs' => $sync->runs()->latest('id')->limit(Sync::KEEP_RUNS)->get()
                ->map(fn (Transfer $run) => $run->toApi())->values(),
        ];
    }
}
