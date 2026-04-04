<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;
use Illuminate\View\View;

class KeyController extends Controller
{
    private const SYSTEM_KEY_NAMES = ['johnny-default', 'johnny-backup'];

    public function index(): View
    {
        $output = $this->runJohnny(['key', 'list']);
        $error = null;
        if ($output === null) {
            $error = 'Could not run `johnny key list`. Ensure sudoers allows the PHP user to run `sudo -u johnny /usr/local/bin/johnny`.';
        }

        $keys = collect($this->parseKeys($output ?? ''))
            ->reject(fn ($k) => in_array($k['name'], self::SYSTEM_KEY_NAMES, true))
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

        if (in_array($validated['name'], self::SYSTEM_KEY_NAMES, true)) {
            return back()->withErrors(['name' => 'This name is reserved for the system.'])->withInput();
        }

        $result = Process::run([
            'sudo', '-u', 'johnny',
            '/usr/local/bin/johnny',
            'key', 'create', $validated['name'],
        ]);

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

        if ($this->isSystemKey($validated['key_id'])) {
            return back()->withErrors(['key_id' => 'System keys cannot be deleted.']);
        }

        $result = Process::run([
            'sudo', '-u', 'johnny',
            '/usr/local/bin/johnny',
            'key', 'delete', '--yes', $validated['key_id'],
        ]);

        if (! $result->successful()) {
            return back()->withErrors(['key_id' => $result->errorOutput() ?: $result->output()]);
        }

        return redirect()->route('keys.index')->with('status', 'Key deleted.');
    }

    private function isSystemKey(string $keyId): bool
    {
        $panelKeyId = config('services.garage.key');
        if ($panelKeyId && $keyId === $panelKeyId) {
            return true;
        }

        $allKeys = $this->parseKeys($this->runJohnny(['key', 'list']) ?? '');
        foreach ($allKeys as $k) {
            if ($k['id'] === $keyId && in_array($k['name'], self::SYSTEM_KEY_NAMES, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    private function parseKeys(string $output): array
    {
        $keys = [];
        foreach (explode("\n", $output) as $line) {
            $line = trim($line);
            // Format: "GKxxxx  2026-04-04  key-name  never" or "GKxxxx  key-name"
            if (preg_match('/^(GK[0-9a-f]+)\s+\d{4}-\d{2}-\d{2}\s+(\S+)/i', $line, $m)) {
                $keys[] = ['id' => trim($m[1]), 'name' => trim($m[2])];
            } elseif (preg_match('/^(GK[0-9a-f]+)\s+(\S+)/i', $line, $m)) {
                $keys[] = ['id' => trim($m[1]), 'name' => trim($m[2])];
            }
        }

        return $keys;
    }

    private function runJohnny(array $args): ?string
    {
        $cmd = array_merge(['sudo', '-u', 'johnny', '/usr/local/bin/johnny'], $args);
        $result = Process::run($cmd);

        return $result->successful() ? $result->output() : null;
    }
}
