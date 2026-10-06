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

        return view('keys.index', [
            'error' => $error,
            'keys' => $this->johnny->userKeys($this->johnny->parseKeyList($output ?? '')),
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

        $result = $this->johnny->createKey($validated['name']);

        if (! $result->successful()) {
            return back()->withErrors(['name' => $this->johnny->errorDetail($result, 'Key creation failed.')])->withInput();
        }

        return redirect()->route('keys.index')
            ->with('new_key', [
                'name' => $validated['name'],
                'credentials' => $this->johnny->parseKeyCreateOutput($result->output()),
                'raw' => $result->output(),
            ])
            ->with('status', 'Key created. Grant bucket permissions from the bucket detail page.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'key_id' => ['required', 'string', 'max:128'],
        ]);

        // Garage resolves id prefixes too: only ever act on an exact, listed key id.
        $keyId = $this->johnny->resolveCanonicalKeyId($validated['key_id']);
        if ($keyId === null) {
            return back()->withErrors(['key_id' => 'Key not found.']);
        }

        if ($this->johnny->isSystemKey($keyId)) {
            return back()->withErrors(['key_id' => 'System keys cannot be deleted.']);
        }

        $result = $this->johnny->deleteKey($keyId);

        if (! $result->successful()) {
            return back()->withErrors(['key_id' => $this->johnny->errorDetail($result, 'Key deletion failed.')]);
        }

        return redirect()->route('keys.index')->with('status', 'Key deleted.');
    }
}
