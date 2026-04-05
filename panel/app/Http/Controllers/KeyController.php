<?php

namespace App\Http\Controllers;

use App\Services\JohnnyCliService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class KeyController extends Controller
{
    public function __construct(
        private JohnnyCliService $johnny
    ) {}

    public function index(): View
    {
        $output = $this->johnny->runJohnny(['key', 'list']);
        $error = null;
        if ($output === null) {
            $error = 'Could not run `johnny key list`. Ensure sudoers allows the PHP user to run `sudo -u johnny /usr/local/bin/johnny`.';
        }

        $keys = collect($this->johnny->parseKeyList($output ?? ''))
            ->reject(fn ($k) => in_array($k['name'], JohnnyCliService::SYSTEM_KEY_NAMES, true))
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();

        return view('keys.index', [
            'error' => $error,
            'keys' => $keys,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:128', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/'],
        ]);

        if (in_array($validated['name'], JohnnyCliService::SYSTEM_KEY_NAMES, true)) {
            return back()->withErrors(['name' => 'This name is reserved for the system.'])->withInput();
        }

        $result = $this->johnny->runJohnnyResult(['key', 'create', $validated['name']]);

        if (! $result->successful()) {
            return back()->withErrors(['name' => $result->errorOutput() ?: $result->output()])->withInput();
        }

        return redirect()->route('keys.index')
            ->with('key_create_output', $result->output())
            ->with('status', 'Key created. Grant bucket permissions from the bucket detail page.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'key_id' => ['required', 'string', 'max:128'],
        ]);

        if ($this->johnny->isSystemKey($validated['key_id'])) {
            return back()->withErrors(['key_id' => 'System keys cannot be deleted.']);
        }

        $result = $this->johnny->runJohnnyResult(['key', 'delete', '--yes', $validated['key_id']]);

        if (! $result->successful()) {
            return back()->withErrors(['key_id' => $result->errorOutput() ?: $result->output()]);
        }

        return redirect()->route('keys.index')->with('status', 'Key deleted.');
    }
}
