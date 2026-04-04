<?php

namespace App\Http\Controllers;

use App\Services\GarageS3;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;
use Illuminate\View\View;

class KeyController extends Controller
{
    public function __construct(
        private GarageS3 $garage
    ) {}

    public function index(): View
    {
        $output = $this->runJohnny(['key', 'list']);
        $error = null;
        if ($output === null) {
            $error = 'Could not run `johnny key list`. Ensure sudoers allows the PHP user to run `sudo -u johnny /usr/local/bin/johnny` (see Johnny install docs).';
        }

        $buckets = collect($this->garage->listBuckets())->pluck('name')->toArray();

        return view('keys.index', [
            'output' => $output ?? '',
            'error' => $error,
            'buckets' => $buckets,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:128', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/'],
            'allow_all_buckets' => ['nullable', 'boolean'],
        ]);

        $result = Process::run([
            'sudo', '-u', 'johnny',
            '/usr/local/bin/johnny',
            'key', 'create', $validated['name'],
        ]);

        if (! $result->successful()) {
            return back()->withErrors(['name' => $result->errorOutput() ?: $result->output()])->withInput();
        }

        if ($request->boolean('allow_all_buckets')) {
            $buckets = collect($this->garage->listBuckets())->pluck('name');
            foreach ($buckets as $bucket) {
                $this->allowKeyOnBucket($validated['name'], $bucket);
            }
        }

        return redirect()->route('keys.index')
            ->with('key_create_output', $result->output())
            ->with('status', $request->boolean('allow_all_buckets')
                ? 'Key created and granted read/write/owner on all existing buckets.'
                : 'Key created. Grant bucket permissions below.');
    }

    public function allow(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'key_name' => ['required', 'string', 'max:128'],
            'bucket' => ['required', 'string', 'max:255'],
            'read' => ['nullable', 'boolean'],
            'write' => ['nullable', 'boolean'],
            'owner' => ['nullable', 'boolean'],
        ]);

        if (! $request->boolean('read') && ! $request->boolean('write') && ! $request->boolean('owner')) {
            return back()->withErrors(['bucket' => 'Select at least one permission (read, write, or owner).'])->withInput();
        }

        $cmd = [
            'sudo', '-u', 'johnny',
            '/usr/local/bin/johnny',
            'bucket', 'allow',
        ];
        if ($request->boolean('read')) {
            $cmd[] = '--read';
        }
        if ($request->boolean('write')) {
            $cmd[] = '--write';
        }
        if ($request->boolean('owner')) {
            $cmd[] = '--owner';
        }
        $cmd[] = $validated['bucket'];
        $cmd[] = '--key';
        $cmd[] = $validated['key_name'];

        $result = Process::run($cmd);

        if (! $result->successful()) {
            return back()->withErrors(['bucket' => $result->errorOutput() ?: $result->output()])->withInput();
        }

        return redirect()->route('keys.index')
            ->with('status', "Permissions granted for key \"{$validated['key_name']}\" on bucket \"{$validated['bucket']}\".");
    }

    public function deny(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'key_name' => ['required', 'string', 'max:128'],
            'bucket' => ['required', 'string', 'max:255'],
            'read' => ['nullable', 'boolean'],
            'write' => ['nullable', 'boolean'],
            'owner' => ['nullable', 'boolean'],
        ]);

        if (! $request->boolean('read') && ! $request->boolean('write') && ! $request->boolean('owner')) {
            return back()->withErrors(['bucket' => 'Select at least one permission to revoke.'])->withInput();
        }

        $cmd = [
            'sudo', '-u', 'johnny',
            '/usr/local/bin/johnny',
            'bucket', 'deny',
        ];
        if ($request->boolean('read')) {
            $cmd[] = '--read';
        }
        if ($request->boolean('write')) {
            $cmd[] = '--write';
        }
        if ($request->boolean('owner')) {
            $cmd[] = '--owner';
        }
        $cmd[] = $validated['bucket'];
        $cmd[] = '--key';
        $cmd[] = $validated['key_name'];

        $result = Process::run($cmd);

        if (! $result->successful()) {
            return back()->withErrors(['bucket' => $result->errorOutput() ?: $result->output()])->withInput();
        }

        return redirect()->route('keys.index')
            ->with('status', "Permissions revoked for key \"{$validated['key_name']}\" on bucket \"{$validated['bucket']}\".");
    }

    private function allowKeyOnBucket(string $keyName, string $bucket): void
    {
        Process::run([
            'sudo', '-u', 'johnny',
            '/usr/local/bin/johnny',
            'bucket', 'allow', '--read', '--write', '--owner', $bucket, '--key', $keyName,
        ]);
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
