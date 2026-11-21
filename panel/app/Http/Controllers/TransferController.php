<?php

namespace App\Http\Controllers;

use App\Models\RemoteConnection;
use App\Models\Sync;
use App\Models\Transfer;
use App\Services\GarageS3;
use App\Services\JohnnyCliService;
use App\Services\RemoteS3;
use App\Services\Transfers\SyncScheduler;
use App\Services\Transfers\TransferLauncher;
use App\Services\Transfers\TransferPlanner;
use App\Support\Intervals;
use App\Support\StorageProviders;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Imports (a bucket of another provider → Johnny) and exports (a Johnny bucket → another
 * provider). Each transfer is an rclone process running in the background.
 */
class TransferController extends Controller
{
    public function __construct(
        private TransferLauncher $launcher,
        private SyncScheduler $scheduler,
        private TransferPlanner $planner,
        private JohnnyCliService $johnny,
        private GarageS3 $garage,
        private RemoteS3 $remote
    ) {}

    public function index(): View
    {
        // Cron does this every minute; doing it here too keeps syncs moving without cron.
        $this->scheduler->runDue();
        $this->launcher->drain();

        $syncs = Sync::query()->with(['remoteConnection', 'latestRun'])->orderBy('id')->get();

        return view('transfers.index', [
            'connections' => RemoteConnection::query()->orderBy('name')->get(),
            'syncs' => $syncs,
            'schedulerRunning' => $syncs->isEmpty() || $this->scheduler->isRunning(),
            // The runs of a sync are listed on its own page.
            'transfers' => Transfer::query()->whereNull('sync_id')->latest('id')->limit(50)->get(),
            'providers' => StorageProviders::all(),
            'rcloneAvailable' => $this->launcher->rcloneAvailable(),
        ]);
    }

    /**
     * The form of one transfer; the links of a connection page fill it in.
     */
    public function create(Request $request): View|RedirectResponse
    {
        $connections = RemoteConnection::query()->orderBy('name')->get();

        if ($connections->isEmpty()) {
            return redirect()->route('transfers.index')->withErrors(['connection' => 'Add a connection to another provider first.']);
        }

        $connection = $connections->firstWhere('id', (int) $request->query('connection')) ?? $connections->first();
        $direction = $request->query('direction') === Transfer::EXPORT ? Transfer::EXPORT : Transfer::IMPORT;

        try {
            $localBuckets = array_column($this->garage->listBuckets(), 'name');
        } catch (\Throwable) {
            $localBuckets = [];
        }

        try {
            $remoteBuckets = array_column($this->remote->listBuckets($connection), 'name');
        } catch (\Throwable) {
            $remoteBuckets = [];
        }

        $remoteBucket = is_string($request->query('remote_bucket')) ? $request->query('remote_bucket') : '';
        $localBucket = is_string($request->query('local_bucket')) ? $request->query('local_bucket') : '';

        return view('transfers.create', [
            'connections' => $connections,
            'connection' => $connection,
            'direction' => $direction,
            'localBuckets' => $localBuckets,
            'remoteBuckets' => $remoteBuckets,
            'remoteBucket' => $remoteBucket,
            'localBucket' => $localBucket !== '' ? $localBucket : strtolower($remoteBucket),
            'schedule' => $request->query('schedule') === 'repeat' ? 'repeat' : 'once',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate(TransferPlanner::rules() + [
            'dry_run' => ['nullable', 'boolean'],
            'schedule' => ['nullable', Rule::in(['once', 'repeat'])],
            'interval' => ['nullable', 'string', 'max:16'],
            'every' => ['nullable', 'integer', 'min:1', 'max:'.Intervals::MAX_MINUTES],
            'unit' => ['nullable', Rule::in(array_keys(Intervals::UNITS))],
        ], TransferPlanner::messages());

        $connection = RemoteConnection::query()->findOrFail($validated['connection_id']);
        $userId = $request->user()?->getAuthIdentifier();
        $repeat = ($validated['schedule'] ?? 'once') === 'repeat';
        $interval = $repeat
            ? Intervals::fromInput($validated['interval'] ?? null, $validated['every'] ?? null, $validated['unit'] ?? null)
            : null;

        if ($repeat && $interval === null) {
            throw ValidationException::withMessages(['interval' => 'Choose how often the sync runs: at least every minute, at most once a year.']);
        }

        $validated['remote_bucket'] = $this->planner->remoteLocation($connection, $validated['remote_bucket']);
        $this->planner->prepareLocalBucket($validated['direction'], $validated['local_bucket']);

        if ($interval !== null) {
            $sync = $this->planner->sync($connection, $validated, $interval, $userId);

            return redirect()->route('syncs.show', $sync)
                ->with('status', 'Sync created: '.strtolower($sync->intervalLabel()).'. The first run has started.');
        }

        $transfer = $this->planner->transfer($connection, $validated, $userId);

        return redirect()->route('transfers.show', $transfer)
            ->with('status', $transfer->isImport() ? 'Import started.' : 'Export started.');
    }

    /**
     * Import several buckets of a connection at once, each into a bucket of the same name.
     */
    public function storeMany(Request $request, RemoteConnection $connection): RedirectResponse
    {
        $validated = $request->validate([
            'buckets' => ['required', 'array', 'min:1', 'max:100'],
            'buckets.*' => ['string', 'regex:'.TransferPlanner::REMOTE_BUCKET],
        ], [
            'buckets.required' => 'Select at least one bucket.',
        ]);

        $queued = 0;
        $skipped = [];

        foreach (array_unique($validated['buckets']) as $remoteBucket) {
            $localBucket = strtolower($remoteBucket);

            $error = preg_match(TransferPlanner::LOCAL_BUCKET, $localBucket)
                ? $this->johnny->ensureBucket($localBucket)
                : 'its name cannot be used on Johnny';

            if ($error !== null) {
                $skipped[] = "{$remoteBucket} ({$error})";

                continue;
            }

            $this->planner->queue($connection, [
                'direction' => Transfer::IMPORT,
                'mode' => Transfer::COPY,
                'local_bucket' => $localBucket,
                'remote_bucket' => $remoteBucket,
                'prefix' => '',
                'dry_run' => false,
                'create_bucket' => false,
            ], $request->user()?->getAuthIdentifier());
            $queued++;
        }

        $this->launcher->drain();

        return redirect()
            ->route('transfers.index')
            ->with('status', $queued > 0 ? ($queued === 1 ? '1 import queued.' : "{$queued} imports queued: they run a few at a time.") : null)
            ->withErrors($skipped === [] ? [] : ['buckets' => 'Not imported: '.implode(', ', $skipped).'.']);
    }

    public function show(Transfer $transfer): View
    {
        $this->launcher->reapStale();

        return view('transfers.show', ['transfer' => $transfer->refresh()]);
    }

    /**
     * What the Transfers page polls: every listed transfer, and whether anything is still moving.
     */
    public function status(): JsonResponse
    {
        $this->scheduler->runDue();
        $this->launcher->drain();

        // What the Transfers page shows: the one-off transfers, and the runs of syncs in progress.
        $transfers = Transfer::query()->whereNull('sync_id')->latest('id')->limit(50)->get()
            ->concat(Transfer::query()->whereNotNull('sync_id')->active()->get());

        return response()->json([
            'active' => $transfers->contains(fn (Transfer $transfer) => $transfer->isActive()),
            'transfers' => $transfers->map(fn (Transfer $transfer) => $transfer->toStatus())->values(),
        ]);
    }

    public function statusOf(Transfer $transfer): JsonResponse
    {
        $this->launcher->drain();

        return response()->json($transfer->refresh()->toStatus(withLog: true));
    }

    public function cancel(Transfer $transfer): RedirectResponse
    {
        $this->planner->cancel($transfer);

        return redirect()->route('transfers.show', $transfer)->with('status', 'Cancelling the transfer. Files already copied stay where they are.');
    }

    /**
     * Run a finished transfer again with the same settings; rclone skips what is already there.
     */
    public function retry(Request $request, Transfer $transfer): RedirectResponse
    {
        if ($transfer->isActive()) {
            return redirect()->route('transfers.show', $transfer)->withErrors(['transfer' => 'This transfer is still running.']);
        }

        // "Run for real" after a dry run, on request.
        $again = $this->planner->again($transfer, $request->boolean('for_real'), $request->user()?->getAuthIdentifier());

        if ($again === null) {
            return redirect()->route('transfers.show', $transfer)->withErrors(['transfer' => 'The connection this transfer used was deleted.']);
        }

        return redirect()->route('transfers.show', $again)->with('status', 'Transfer started again.');
    }

    public function destroy(Transfer $transfer): RedirectResponse
    {
        if ($transfer->isActive()) {
            return redirect()->route('transfers.show', $transfer)->withErrors(['transfer' => 'Cancel the transfer before removing it.']);
        }

        $transfer->delete();

        return redirect()->route('transfers.index')->with('status', 'Transfer removed from the history.');
    }
}
