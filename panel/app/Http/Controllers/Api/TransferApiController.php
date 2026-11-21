<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RemoteConnection;
use App\Models\Transfer;
use App\Services\Transfers\TransferLauncher;
use App\Services\Transfers\TransferPlanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * One-off imports and exports. Each runs in the background: create it, then poll it.
 */
class TransferApiController extends Controller
{
    public function __construct(
        private TransferPlanner $planner,
        private TransferLauncher $launcher,
    ) {}

    /**
     * The latest transfers, newest first. `status` and `sync_id` narrow the list; without
     * `sync_id` the runs of scheduled syncs are left out.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in([Transfer::PENDING, Transfer::RUNNING, Transfer::COMPLETED, Transfer::FAILED, Transfer::CANCELLED])],
            'sync_id' => ['nullable', 'integer'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $this->launcher->drain();

        $transfers = Transfer::query()
            ->when(isset($validated['status']), fn ($query) => $query->where('status', $validated['status']))
            ->when(
                isset($validated['sync_id']),
                fn ($query) => $query->where('sync_id', $validated['sync_id']),
                fn ($query) => $query->whereNull('sync_id'),
            )
            ->latest('id')
            ->limit((int) ($validated['limit'] ?? 50))
            ->get();

        return response()->json(['data' => $transfers->map(fn (Transfer $transfer) => $transfer->toApi())->values()]);
    }

    /**
     * Start an import (`direction: import`, another provider → Johnny) or an export.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate(TransferPlanner::rules() + [
            'dry_run' => ['nullable', 'boolean'],
        ], TransferPlanner::messages());

        $connection = RemoteConnection::query()->findOrFail($validated['connection_id']);
        $validated['remote_bucket'] = $this->planner->remoteLocation($connection, $validated['remote_bucket']);
        $this->planner->prepareLocalBucket($validated['direction'], $validated['local_bucket']);

        $transfer = $this->planner->transfer($connection, $validated, $request->user()?->getAuthIdentifier());

        return response()->json(['data' => $transfer->refresh()->toApi()], 201);
    }

    /**
     * State, counters and the tail of the rclone log: poll this while `active` is true.
     */
    public function show(Transfer $transfer): JsonResponse
    {
        $this->launcher->drain();

        return response()->json(['data' => $transfer->refresh()->toApi(withLog: true)]);
    }

    public function cancel(Transfer $transfer): JsonResponse
    {
        if (! $transfer->isActive()) {
            return response()->json(['message' => 'This transfer has already finished.'], 409);
        }

        $this->planner->cancel($transfer);

        return response()->json(['data' => $transfer->refresh()->toApi()]);
    }

    /**
     * Run a finished transfer again. `for_real: true` turns a dry run into a real run.
     */
    public function retry(Request $request, Transfer $transfer): JsonResponse
    {
        $validated = $request->validate(['for_real' => ['nullable', 'boolean']]);

        if ($transfer->isActive()) {
            return response()->json(['message' => 'This transfer is still running.'], 409);
        }

        $again = $this->planner->again($transfer, (bool) ($validated['for_real'] ?? false), $request->user()?->getAuthIdentifier());

        if ($again === null) {
            return response()->json(['message' => 'The connection this transfer used was deleted.'], 409);
        }

        return response()->json(['data' => $again->refresh()->toApi()], 201);
    }

    public function destroy(Transfer $transfer): JsonResponse|Response
    {
        if ($transfer->isActive()) {
            return response()->json(['message' => 'Cancel the transfer before removing it.'], 409);
        }

        $transfer->delete();

        return response()->noContent();
    }
}
