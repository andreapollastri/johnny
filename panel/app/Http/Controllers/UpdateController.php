<?php

namespace App\Http\Controllers;

use App\Services\JohnnyUpdates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * Johnny's updates and the server's, for administrators. The panel has no roles: every
 * signed-in user is an administrator, so these routes sit behind `auth` like the rest.
 */
class UpdateController extends Controller
{
    public function __construct(private JohnnyUpdates $updates) {}

    public function show(): View
    {
        return view('updates', [
            'managed' => $this->updates->managed(),
            'version' => $this->updates->version(),
            'state' => $this->updates->state(),
            'channel' => $this->updates->channel(),
            'automatic' => $this->updates->automatic(),
            'available' => $this->updates->available(),
            'waiting' => $this->updates->waiting(),
            'running' => $this->updates->running(),
            'server' => $this->updates->systemState(),
            'serverRunning' => $this->updates->systemRunning(),
            'serverAutomatic' => $this->updates->systemAutomatic(),
            'releaseUrl' => fn (string $build) => $this->updates->releaseUrl($build),
        ]);
    }

    /** What the page follows while an update runs (it answers 503 while the panel is in maintenance). */
    public function status(): JsonResponse
    {
        $state = $this->updates->state();
        $server = $this->updates->systemState();

        return response()->json([
            'running' => $this->updates->running(),
            'message' => $state['message'] ?? null,
            'log' => array_values((array) ($state['log'] ?? [])),
            'system' => [
                'running' => $this->updates->systemRunning(),
                'message' => $server['message'] ?? null,
                'log' => array_values((array) ($server['log'] ?? [])),
            ],
        ]);
    }

    public function check(): RedirectResponse
    {
        return $this->act(fn () => $this->updates->check(), 'Checked for updates.');
    }

    public function start(Request $request): RedirectResponse
    {
        $force = $request->boolean('force');

        return $this->act(
            fn () => $this->updates->start($force),
            $force ? 'Forced update started.' : 'Update started.',
        );
    }

    public function channel(Request $request): RedirectResponse
    {
        $channel = $request->validate(['channel' => ['required', 'in:stable,beta']])['channel'];

        return $this->act(
            fn () => $this->updates->setChannel($channel),
            $channel === 'beta' ? 'Johnny now follows the main branch (beta).' : 'Johnny now follows the stable releases.',
        );
    }

    public function auto(Request $request): RedirectResponse
    {
        $on = (bool) $request->validate(['enabled' => ['required', 'boolean']])['enabled'];

        return $this->act(
            fn () => $this->updates->setAutomatic($on),
            $on ? 'Automatic updates are on.' : 'Automatic updates are off.',
        );
    }

    public function systemStart(): RedirectResponse
    {
        return $this->act(fn () => $this->updates->startSystem(), 'Server update started.');
    }

    public function systemAuto(Request $request): RedirectResponse
    {
        $on = (bool) $request->validate(['enabled' => ['required', 'boolean']])['enabled'];

        return $this->act(
            fn () => $this->updates->setSystemAutomatic($on),
            $on ? 'Nightly server updates are on.' : 'Nightly server updates are off.',
        );
    }

    private function act(callable $action, string $done): RedirectResponse
    {
        if (! $this->updates->managed()) {
            return redirect()
                ->route('updates.show')
                ->withErrors(['updates' => 'Updates are managed by the installer on the server.']);
        }

        try {
            $action();
        } catch (RuntimeException $e) {
            return redirect()->route('updates.show')->withErrors(['updates' => $e->getMessage()]);
        }

        return redirect()->route('updates.show')->with('status', $done);
    }
}
