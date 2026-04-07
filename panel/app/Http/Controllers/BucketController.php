<?php

namespace App\Http\Controllers;

use App\Services\GarageS3;
use App\Services\JohnnyCliService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BucketController extends Controller
{
    public function __construct(
        private GarageS3 $garage,
        private JohnnyCliService $johnny
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
                    ? 'The panel key does not have access to this bucket. Go to the Keys tab and grant permissions to the panel key.'
                    : $e->getMessage();
            }
        }

        if ($tab === 'keys') {
            [$authorizedKeys, $bucketInfoRaw] = $this->johnny->parseBucketInfo($bucket);

            // Hide system keys from the authorized list
            $authorizedKeys = array_values(array_filter(
                $authorizedKeys,
                fn ($k) => ! in_array($k['name'], JohnnyCliService::SYSTEM_KEY_NAMES, true),
            ));

            // Only show user-created keys in the grant dropdown
            $allKeys = array_values(array_filter(
                $this->johnny->listAllKeys(),
                fn ($k) => ! in_array($k['name'], JohnnyCliService::SYSTEM_KEY_NAMES, true),
            ));
            usort($authorizedKeys, fn ($a, $b) => strcasecmp($a['name'], $b['name']) ?: strcmp($a['id'], $b['id']));
            usort($allKeys, fn ($a, $b) => strcasecmp($a['name'], $b['name']) ?: strcmp($a['id'], $b['id']));
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
            'isProtected' => in_array($bucket, JohnnyCliService::PROTECTED_BUCKETS, true),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9][a-z0-9._-]{1,254}$/'],
        ]);

        $name = $validated['name'];

        $result = $this->johnny->runJohnnyResult(['bucket', 'create', $name]);

        if (! $result->successful()) {
            return back()->withErrors(['name' => $result->errorOutput() ?: $result->output()])->withInput();
        }

        $this->johnny->ensureDefaultSystemKeysOnBucket($name);

        return redirect()->route('buckets.index')->with('status', 'Bucket created.');
    }

    public function destroy(string $bucket): RedirectResponse
    {
        if (in_array($bucket, JohnnyCliService::PROTECTED_BUCKETS, true)) {
            return back()->withErrors(['error' => "The \"{$bucket}\" bucket is protected and cannot be deleted."]);
        }

        $result = $this->johnny->runJohnnyResult(['bucket', 'delete', '--yes', $bucket]);

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

        $result = $this->johnny->bucketAllow(
            $bucket,
            $validated['key_id'],
            $request->boolean('read'),
            $request->boolean('write'),
            $request->boolean('owner'),
        );

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

        $result = $this->johnny->bucketDeny(
            $bucket,
            $validated['key_id'],
            $request->boolean('read'),
            $request->boolean('write'),
            $request->boolean('owner'),
        );

        if (! $result->successful()) {
            return back()->withErrors(['key_id' => $result->errorOutput() ?: $result->output()])->withInput();
        }

        return redirect()->route('buckets.show', ['bucket' => $bucket, 'tab' => 'keys'])
            ->with('status', 'Permissions revoked.');
    }
}
