<?php

namespace App\Http\Controllers;

use App\Services\GarageS3;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;
use Illuminate\View\View;

class BucketController extends Controller
{
    public function __construct(
        private GarageS3 $garage
    ) {}

    public function index(): View
    {
        $buckets = $this->garage->listBuckets();

        return view('buckets.index', compact('buckets'));
    }

    public function show(Request $request, string $bucket): View
    {
        $tab = $request->query('tab', 'objects');
        $prefix = (string) $request->query('prefix', '');

        $objects = [];
        $authorizedKeys = [];
        $bucketInfoRaw = '';
        $allKeys = [];

        if ($tab === 'objects') {
            $objects = $this->garage->listObjects($bucket, $prefix);
        }

        if ($tab === 'keys') {
            [$authorizedKeys, $bucketInfoRaw] = $this->parseBucketInfo($bucket);
            $allKeys = $this->parseKeyNames();
        }

        return view('buckets.show', [
            'bucket' => $bucket,
            'tab' => $tab,
            'prefix' => $prefix,
            'objects' => $objects,
            'authorizedKeys' => $authorizedKeys,
            'bucketInfoRaw' => $bucketInfoRaw,
            'allKeys' => $allKeys,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9][a-z0-9._-]{1,254}$/'],
        ]);

        $name = $validated['name'];

        $result = Process::run([
            'sudo', '-u', 'johnny',
            '/usr/local/bin/johnny',
            'bucket', 'create', $name,
        ]);

        if (! $result->successful()) {
            return back()->withErrors(['name' => $result->errorOutput() ?: $result->output()])->withInput();
        }

        $this->allowKeyOnBucket(config('services.garage.key_name', 'johnny-default'), $name);

        return redirect()->route('buckets.index')->with('status', 'Bucket created.');
    }

    public function destroy(string $bucket): RedirectResponse
    {
        $result = Process::input($bucket."\n")->run([
            'sudo', '-u', 'johnny',
            '/usr/local/bin/johnny',
            'bucket', 'delete', $bucket,
        ]);

        if (! $result->successful()) {
            return back()->withErrors(['error' => $result->errorOutput() ?: $result->output()]);
        }

        return redirect()->route('buckets.index')->with('status', 'Bucket deleted.');
    }

    public function allow(Request $request, string $bucket): RedirectResponse
    {
        $validated = $request->validate([
            'key_name' => ['required', 'string', 'max:128'],
            'read' => ['nullable', 'boolean'],
            'write' => ['nullable', 'boolean'],
            'owner' => ['nullable', 'boolean'],
        ]);

        if (! $request->boolean('read') && ! $request->boolean('write') && ! $request->boolean('owner')) {
            return back()->withErrors(['key_name' => 'Select at least one permission.'])->withInput();
        }

        $cmd = ['sudo', '-u', 'johnny', '/usr/local/bin/johnny', 'bucket', 'allow'];
        if ($request->boolean('read')) {
            $cmd[] = '--read';
        }
        if ($request->boolean('write')) {
            $cmd[] = '--write';
        }
        if ($request->boolean('owner')) {
            $cmd[] = '--owner';
        }
        $cmd[] = $bucket;
        $cmd[] = '--key';
        $cmd[] = $validated['key_name'];

        $result = Process::run($cmd);

        if (! $result->successful()) {
            return back()->withErrors(['key_name' => $result->errorOutput() ?: $result->output()])->withInput();
        }

        return redirect()->route('buckets.show', ['bucket' => $bucket, 'tab' => 'keys'])
            ->with('status', "Permissions granted for \"{$validated['key_name']}\".");
    }

    public function deny(Request $request, string $bucket): RedirectResponse
    {
        $validated = $request->validate([
            'key_name' => ['required', 'string', 'max:128'],
            'read' => ['nullable', 'boolean'],
            'write' => ['nullable', 'boolean'],
            'owner' => ['nullable', 'boolean'],
        ]);

        if (! $request->boolean('read') && ! $request->boolean('write') && ! $request->boolean('owner')) {
            return back()->withErrors(['key_name' => 'Select at least one permission to revoke.'])->withInput();
        }

        $cmd = ['sudo', '-u', 'johnny', '/usr/local/bin/johnny', 'bucket', 'deny'];
        if ($request->boolean('read')) {
            $cmd[] = '--read';
        }
        if ($request->boolean('write')) {
            $cmd[] = '--write';
        }
        if ($request->boolean('owner')) {
            $cmd[] = '--owner';
        }
        $cmd[] = $bucket;
        $cmd[] = '--key';
        $cmd[] = $validated['key_name'];

        $result = Process::run($cmd);

        if (! $result->successful()) {
            return back()->withErrors(['key_name' => $result->errorOutput() ?: $result->output()])->withInput();
        }

        return redirect()->route('buckets.show', ['bucket' => $bucket, 'tab' => 'keys'])
            ->with('status', "Permissions revoked for \"{$validated['key_name']}\".");
    }

    /**
     * Parse `garage bucket info` output to extract authorized keys.
     *
     * @return array{0: list<array{id: string, name: string, permissions: string}>, 1: string}
     */
    private function parseBucketInfo(string $bucket): array
    {
        $result = Process::run([
            'sudo', '-u', 'johnny',
            '/usr/local/bin/johnny',
            'bucket', 'info', $bucket,
        ]);

        $raw = $result->successful() ? $result->output() : '';
        $keys = [];

        // Garage outputs lines like: "  GKxxxx (name): read, write, owner"
        // or "  GKxxxx: read, write" (no name)
        // Format varies; we look for lines starting with whitespace + GK
        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            // Match: GK<hex> (<name>): <permissions>
            if (preg_match('/^(GK[0-9a-f]+)\s+\(([^)]+)\)\s*:\s*(.+)$/i', $line, $m)) {
                $keys[] = ['id' => $m[1], 'name' => trim($m[2]), 'permissions' => trim($m[3])];
            }
            // Match: GK<hex>: <permissions> (no name)
            elseif (preg_match('/^(GK[0-9a-f]+)\s*:\s*(.+)$/i', $line, $m)) {
                $keys[] = ['id' => $m[1], 'name' => '', 'permissions' => trim($m[2])];
            }
        }

        return [$keys, $raw];
    }

    /**
     * Parse `johnny key list` to extract key names for the dropdown.
     *
     * @return list<string>
     */
    private function parseKeyNames(): array
    {
        $result = Process::run([
            'sudo', '-u', 'johnny',
            '/usr/local/bin/johnny',
            'key', 'list',
        ]);

        if (! $result->successful()) {
            return [];
        }

        $names = [];
        foreach (explode("\n", $result->output()) as $line) {
            $line = trim($line);
            // Garage key list outputs lines like: "GKxxxx  key-name"
            if (preg_match('/^GK[0-9a-f]+\s+(.+)$/i', $line, $m)) {
                $names[] = trim($m[1]);
            }
        }

        return $names;
    }

    private function allowKeyOnBucket(string $keyName, string $bucket): void
    {
        Process::run([
            'sudo', '-u', 'johnny',
            '/usr/local/bin/johnny',
            'bucket', 'allow', '--read', '--write', '--owner', $bucket, '--key', $keyName,
        ]);
    }
}
