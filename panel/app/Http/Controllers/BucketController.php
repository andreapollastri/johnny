<?php

namespace App\Http\Controllers;

use App\Services\GarageS3;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;
use Illuminate\View\View;

class BucketController extends Controller
{
    private const SYSTEM_KEY_NAMES = ['johnny-default', 'johnny-backup'];
    private const PROTECTED_BUCKETS = ['default'];

    public function __construct(
        private GarageS3 $garage
    ) {}

    public function index(): View
    {
        $error = '';
        try {
            $buckets = $this->garage->listBuckets();
        } catch (\Throwable $e) {
            $buckets = [];
            $error = str_contains($e->getMessage(), 'AccessDenied')
                ? 'The panel S3 key does not have permission to list buckets. Check GARAGE_* credentials in panel/.env.'
                : $e->getMessage();
        }

        return view('buckets.index', compact('buckets', 'error'));
    }

    public function show(Request $request, string $bucket): View
    {
        $tab = $request->query('tab', 'objects');
        $prefix = (string) $request->query('prefix', '');

        $objects = [];
        $folders = [];
        $objectsError = '';
        $authorizedKeys = [];
        $bucketInfoRaw = '';
        $allKeys = [];

        if ($tab === 'objects') {
            try {
                $result = $this->garage->listObjects($bucket, $prefix);
                $folders = $result['folders'];
                $objects = $result['files'];
            } catch (\Throwable $e) {
                $objectsError = str_contains($e->getMessage(), 'AccessDenied')
                    ? "The panel key does not have access to this bucket. Go to the Keys tab and grant permissions to the panel key."
                    : $e->getMessage();
            }
        }

        if ($tab === 'keys') {
            [$authorizedKeys, $bucketInfoRaw] = $this->parseBucketInfo($bucket);

            // Hide system keys from the authorized list
            $authorizedKeys = array_values(array_filter(
                $authorizedKeys,
                fn ($k) => ! in_array($k['name'], self::SYSTEM_KEY_NAMES, true),
            ));

            // Only show user-created keys in the grant dropdown
            $allKeys = array_values(array_filter(
                $this->parseKeys(),
                fn ($k) => ! in_array($k['name'], self::SYSTEM_KEY_NAMES, true),
            ));
        }

        return view('buckets.show', [
            'bucket' => $bucket,
            'tab' => $tab,
            'prefix' => $prefix,
            'objects' => $objects,
            'folders' => $folders,
            'objectsError' => $objectsError,
            'authorizedKeys' => $authorizedKeys,
            'bucketInfoRaw' => $bucketInfoRaw,
            'allKeys' => $allKeys,
            'isProtected' => in_array($bucket, self::PROTECTED_BUCKETS, true),
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
        if (in_array($bucket, self::PROTECTED_BUCKETS, true)) {
            return back()->withErrors(['error' => "The \"{$bucket}\" bucket is protected and cannot be deleted."]);
        }

        $result = Process::run([
            'sudo', '-u', 'johnny',
            '/usr/local/bin/johnny',
            'bucket', 'delete', '--yes', $bucket,
        ]);

        if (! $result->successful()) {
            return back()->withErrors(['error' => $result->errorOutput() ?: $result->output()]);
        }

        return redirect()->route('buckets.index')->with('status', 'Bucket deleted.');
    }

    public function allow(Request $request, string $bucket): RedirectResponse
    {
        $validated = $request->validate([
            'key_id' => ['required', 'string', 'max:128'],
            'read' => ['nullable', 'boolean'],
            'write' => ['nullable', 'boolean'],
            'owner' => ['nullable', 'boolean'],
        ]);

        if (! $request->boolean('read') && ! $request->boolean('write') && ! $request->boolean('owner')) {
            return back()->withErrors(['key_id' => 'Select at least one permission.'])->withInput();
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
        $cmd[] = $validated['key_id'];

        $result = Process::run($cmd);

        if (! $result->successful()) {
            return back()->withErrors(['key_id' => $result->errorOutput() ?: $result->output()])->withInput();
        }

        return redirect()->route('buckets.show', ['bucket' => $bucket, 'tab' => 'keys'])
            ->with('status', 'Permissions granted.');
    }

    public function deny(Request $request, string $bucket): RedirectResponse
    {
        $validated = $request->validate([
            'key_id' => ['required', 'string', 'max:128'],
            'read' => ['nullable', 'boolean'],
            'write' => ['nullable', 'boolean'],
            'owner' => ['nullable', 'boolean'],
        ]);

        if (! $request->boolean('read') && ! $request->boolean('write') && ! $request->boolean('owner')) {
            return back()->withErrors(['key_id' => 'Select at least one permission to revoke.'])->withInput();
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
        $cmd[] = $validated['key_id'];

        $result = Process::run($cmd);

        if (! $result->successful()) {
            return back()->withErrors(['key_id' => $result->errorOutput() ?: $result->output()])->withInput();
        }

        return redirect()->route('buckets.show', ['bucket' => $bucket, 'tab' => 'keys'])
            ->with('status', 'Permissions revoked.');
    }

    /**
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

        foreach (explode("\n", $raw) as $line) {
            $trimmed = trim($line);
            if (preg_match('/^([RWO]+)\s+(GK[0-9a-f]+)\s*(.*)$/i', $trimmed, $m)) {
                $perms = str_split(strtoupper(trim($m[1])));
                $readable = [];
                if (in_array('R', $perms)) $readable[] = 'read';
                if (in_array('W', $perms)) $readable[] = 'write';
                if (in_array('O', $perms)) $readable[] = 'owner';

                $keys[] = [
                    'id' => trim($m[2]),
                    'name' => trim($m[3]),
                    'permissions' => implode(', ', $readable),
                ];
            }
        }

        return [$keys, $raw];
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    private function parseKeys(): array
    {
        $result = Process::run([
            'sudo', '-u', 'johnny',
            '/usr/local/bin/johnny',
            'key', 'list',
        ]);

        if (! $result->successful()) {
            return [];
        }

        $keys = [];
        foreach (explode("\n", $result->output()) as $line) {
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

    private function allowKeyOnBucket(string $keyName, string $bucket): void
    {
        Process::run([
            'sudo', '-u', 'johnny',
            '/usr/local/bin/johnny',
            'bucket', 'allow', '--read', '--write', '--owner', $bucket, '--key', $keyName,
        ]);
    }
}
