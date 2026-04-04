<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;
use Illuminate\View\View;

class KeyController extends Controller
{
    public function index(): View
    {
        $output = $this->runJohnny(['key', 'list']);
        $error = null;
        if ($output === null) {
            $error = 'Could not run `johnny key list`. Ensure sudoers allows the PHP user to run `sudo -u johnny /usr/local/bin/johnny` (see Johnny install docs).';
        }

        return view('keys.index', [
            'output' => $output ?? '',
            'error' => $error,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:128', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/'],
        ]);

        $result = Process::run([
            'sudo', '-u', 'johnny',
            '/usr/local/bin/johnny',
            'key', 'create', $validated['name'],
        ]);

        if (! $result->successful()) {
            return back()->withErrors(['name' => $result->errorOutput() ?: $result->output()])->withInput();
        }

        return redirect()->route('keys.index')->with('key_create_output', $result->output());
    }

    private function runJohnny(array $args): ?string
    {
        $cmd = array_merge(['sudo', '-u', 'johnny', '/usr/local/bin/johnny'], $args);
        $result = Process::run($cmd);

        if (! $result->successful()) {
            return null;
        }

        return $result->output();
    }
}
