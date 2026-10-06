<?php

namespace App\Http\Controllers;

use App\Services\GarageS3;
use App\Services\JohnnyCliService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class BucketController extends Controller
{
    /** Objects per page in the file browser. */
    private const PAGE_SIZE = 200;

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

        return view('buckets.index', [
            'buckets' => $buckets,
            'error' => $error,
            'protectedBuckets' => JohnnyCliService::PROTECTED_BUCKETS,
        ]);
    }

    public function show(Request $request, string $bucket): View
    {
        $tab = $request->query('tab') === 'keys' ? 'keys' : 'objects';
        $prefix = (string) $request->query('prefix', '');
        $token = $request->query('token');

        $objects = [];
        $folders = [];
        $nextToken = null;
        $objectsError = '';
        $authorizedKeys = [];
        $bucketInfoRaw = '';
        $allKeys = [];

        if ($tab === 'objects') {
            try {
                $result = $this->garage->listObjects($bucket, $prefix, is_string($token) ? $token : null, self::PAGE_SIZE);
                $folders = $result['folders'];
                $objects = $result['files'];
                $nextToken = $result['next_token'];
            } catch (\Throwable $e) {
                $objectsError = str_contains($e->getMessage(), 'AccessDenied')
                    ? 'The panel key does not have access to this bucket. Go to the Keys tab and grant permissions to the panel key.'
                    : $e->getMessage();
            }
        }

        if ($tab === 'keys') {
            [$authorizedKeys, $bucketInfoRaw] = $this->johnny->parseBucketInfo($bucket);

            // System keys are managed by Johnny itself: hide them from both lists.
            $authorizedKeys = $this->johnny->userKeys($authorizedKeys);
            $allKeys = $this->johnny->userKeys($this->johnny->listAllKeys());
        }

        return view('buckets.show', [
            'bucket' => $bucket,
            'tab' => $tab,
            'prefix' => $prefix,
            'objects' => $objects,
            'folders' => $folders,
            'nextToken' => $nextToken,
            'isPaged' => is_string($token) && $token !== '',
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

        $result = $this->johnny->createBucket($name);

        if (! $result->successful()) {
            return back()->withErrors(['name' => $this->johnny->errorDetail($result, 'Bucket creation failed.')])->withInput();
        }

        $this->johnny->ensureDefaultSystemKeysOnBucket($name);

        return redirect()->route('buckets.show', $name)->with('status', "Bucket \"{$name}\" created.");
    }

    public function destroy(string $bucket): RedirectResponse
    {
        if (in_array($bucket, JohnnyCliService::PROTECTED_BUCKETS, true)) {
            return back()->withErrors(['error' => "The \"{$bucket}\" bucket is protected and cannot be deleted."]);
        }

        $result = $this->johnny->deleteBucket($bucket);

        if (! $result->successful()) {
            return back()->withErrors(['error' => $this->johnny->errorDetail($result, 'Bucket deletion failed.')]);
        }

        return redirect()->route('buckets.index')->with('status', "Bucket \"{$bucket}\" deleted.");
    }

    public function allow(Request $request, string $bucket): RedirectResponse
    {
        [$keyId, $flags] = $this->validatePermissionRequest($request, 'Select at least one permission.');

        $result = $this->johnny->bucketAllow($bucket, $keyId, ...$flags);

        if (! $result->successful()) {
            return back()->withErrors(['key_id' => $this->johnny->errorDetail($result, 'Granting permissions failed.')])->withInput();
        }

        return redirect()->route('buckets.show', ['bucket' => $bucket, 'tab' => 'keys'])
            ->with('status', 'Permissions granted.');
    }

    public function deny(Request $request, string $bucket): RedirectResponse
    {
        [$keyId, $flags] = $this->validatePermissionRequest($request, 'Select at least one permission to revoke.');

        if ($this->johnny->isSystemKey($keyId)) {
            return back()->withErrors(['key_id' => 'System key permissions are managed by Johnny and cannot be revoked.']);
        }

        $result = $this->johnny->bucketDeny($bucket, $keyId, ...$flags);

        if (! $result->successful()) {
            return back()->withErrors(['key_id' => $this->johnny->errorDetail($result, 'Revoking permissions failed.')])->withInput();
        }

        return redirect()->route('buckets.show', ['bucket' => $bucket, 'tab' => 'keys'])
            ->with('status', 'Permissions revoked.');
    }

    /**
     * Validates the grant/revoke form and resolves the key to its canonical id: Garage also
     * matches id prefixes, so the raw input must never reach the CLI.
     *
     * @return array{0: string, 1: array{0: bool, 1: bool, 2: bool}}
     *
     * @throws ValidationException
     */
    private function validatePermissionRequest(Request $request, string $noFlagsMessage): array
    {
        $validated = $request->validate([
            'key_id' => ['required', 'string', 'max:128'],
            'read' => ['nullable', 'boolean'],
            'write' => ['nullable', 'boolean'],
            'owner' => ['nullable', 'boolean'],
        ]);

        $flags = [$request->boolean('read'), $request->boolean('write'), $request->boolean('owner')];

        if (! in_array(true, $flags, true)) {
            throw ValidationException::withMessages(['key_id' => $noFlagsMessage]);
        }

        $keyId = $this->johnny->resolveCanonicalKeyId($validated['key_id'])
            ?? throw ValidationException::withMessages(['key_id' => 'Key not found.']);

        return [$keyId, $flags];
    }
}
