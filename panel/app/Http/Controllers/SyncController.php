<?php

namespace App\Http\Controllers;

use App\Models\Sync;
use App\Models\Transfer;
use App\Services\Transfers\SyncScheduler;
use App\Services\Transfers\TransferLauncher;
use App\Support\Intervals;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Scheduled syncs: transfers that repeat on an interval. They are created from the new transfer
 * form (TransferController::store) and managed here.
 */
class SyncController extends Controller
{
    public function __construct(
        private SyncScheduler $scheduler,
        private TransferLauncher $launcher,
    ) {}

    public function show(Sync $sync): View
    {
        $this->scheduler->runDue();
        $this->launcher->drain();

        return view('syncs.show', [
            'sync' => $sync->refresh(),
            'runs' => $sync->runs()->latest('id')->limit(Sync::KEEP_RUNS)->get(),
            'schedulerRunning' => $this->scheduler->isRunning(),
        ]);
    }

    /**
     * Change how often the sync runs and whether it copies or mirrors.
     */
    public function update(Request $request, Sync $sync): RedirectResponse
    {
        $validated = $request->validate([
            'interval' => ['required', 'string', 'max:16'],
            'every' => ['nullable', 'integer', 'min:1', 'max:'.Intervals::MAX_MINUTES],
            'unit' => ['nullable', Rule::in(array_keys(Intervals::UNITS))],
            'mode' => ['required', Rule::in([Transfer::COPY, Transfer::SYNC])],
        ]);

        $interval = Intervals::fromInput($validated['interval'], $validated['every'] ?? null, $validated['unit'] ?? null);

        if ($interval === null) {
            return back()->withErrors(['interval' => 'Choose how often the sync runs: at least every minute, at most once a year.'])->withInput();
        }

        // On the minute, like every run the scheduler plans (see SyncScheduler::nextRun).
        $next = ($sync->last_run_at ?? now())->copy()->addMinutes($interval)->startOfMinute();

        $sync->forceFill([
            'interval_minutes' => $interval,
            'mode' => $validated['mode'],
            'next_run_at' => $next->isPast() ? now() : $next,
        ])->save();

        return redirect()->route('syncs.show', $sync)->with('status', 'Sync saved: '.strtolower($sync->intervalLabel()).'.');
    }

    /**
     * Pause the sync, or resume it: a resumed sync runs right away.
     */
    public function toggle(Sync $sync): RedirectResponse
    {
        $sync->forceFill([
            'enabled' => ! $sync->enabled,
            'next_run_at' => $sync->enabled ? $sync->next_run_at : now(),
        ])->save();

        if ($sync->enabled) {
            $this->scheduler->runDue();
        }

        return back()->with('status', $sync->enabled ? 'Sync resumed.' : 'Sync paused. A run in progress still finishes.');
    }

    /**
     * Run the sync now, without waiting for its next time.
     */
    public function run(Sync $sync): RedirectResponse
    {
        $run = $this->scheduler->start($sync);

        if ($run === null) {
            return back()->withErrors(['sync' => 'A run of this sync is still in progress.']);
        }

        $this->launcher->drain();

        return redirect()->route('transfers.show', $run)->with('status', 'Sync run started.');
    }

    public function destroy(Sync $sync): RedirectResponse
    {
        if ($sync->runs()->active()->exists()) {
            return back()->withErrors(['sync' => 'A run of this sync is in progress. Cancel it or wait for it to finish.']);
        }

        $sync->runs()->delete();
        $sync->delete();

        return redirect()->route('transfers.index')->with('status', 'Sync deleted. The files it copied stay where they are.');
    }
}
