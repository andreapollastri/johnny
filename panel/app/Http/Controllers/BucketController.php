<?php

namespace App\Http\Controllers;

use App\Services\GarageS3;
use App\Services\GlobalKeys;
use App\Services\JohnnyCliService;
use App\Support\Format;
use App\Support\KeyScopes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class BucketController extends Controller
{
    /** Objects per page in the file browser. */
    private const PAGE_SIZE = 200;

    private const TABS = ['objects', 'keys', 'settings', 'connect'];

    public function __construct(
        private GarageS3 $garage,
        private JohnnyCliService $johnny,
        private GlobalKeys $globalKeys,
    ) {}

    public function index(Request $request): View|JsonResponse
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

        // The "Jump to…" palette asks for the names only.
        if ($request->expectsJson()) {
            return response()->json(['buckets' => array_column($buckets, 'name')]);
        }

        return view('buckets.index', [
            'buckets' => $buckets,
            'error' => $error,
            'protectedBuckets' => JohnnyCliService::PROTECTED_BUCKETS,
        ]);
    }

    public function show(Request $request, string $bucket): View
    {
        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'objects';
        $prefix = (string) $request->query('prefix', '');
        $search = is_string($request->query('q')) ? trim($request->query('q')) : '';
        $token = $request->query('token');

        $objects = [];
        $folders = [];
        $nextToken = null;
        $objectsError = '';
        $authorizedKeys = [];
        $bucketInfoRaw = '';
        $allKeys = [];
        $globalKeys = [];
        $usage = null;
        $cors = null;
        $lifecycle = null;
        $settingsErrors = [];

        if ($tab === 'objects') {
            try {
                // S3 can only search by key prefix: the filter matches names that start with it.
                $result = $this->garage->listObjects($bucket, $prefix.$search, is_string($token) ? $token : null, self::PAGE_SIZE);
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
            $globalKeys = $this->globalKeys->all();
        }

        if ($tab === 'settings') {
            $usage = $this->johnny->bucketUsage($bucket);

            try {
                $cors = $this->garage->getCors($bucket);
            } catch (\Throwable $e) {
                $settingsErrors[] = 'CORS rules could not be read: '.$e->getMessage();
            }

            try {
                $lifecycle = $this->garage->getLifecycle($bucket);
            } catch (\Throwable $e) {
                $settingsErrors[] = 'Lifecycle rules could not be read: '.$e->getMessage();
            }
        }

        return view('buckets.show', [
            'bucket' => $bucket,
            'tab' => $tab,
            'prefix' => $prefix,
            'search' => $search,
            'objects' => $objects,
            'folders' => $folders,
            'nextToken' => $nextToken,
            'isPaged' => is_string($token) && $token !== '',
            'objectsError' => $objectsError,
            'authorizedKeys' => $authorizedKeys,
            'bucketInfoRaw' => $bucketInfoRaw,
            'allKeys' => $allKeys,
            'globalKeys' => $globalKeys,
            'scopes' => KeyScopes::all(),
            'isProtected' => in_array($bucket, JohnnyCliService::PROTECTED_BUCKETS, true),
            'usage' => $usage,
            'cors' => $cors,
            'lifecycle' => $lifecycle,
            'settingsErrors' => $settingsErrors,
            'endpoint' => (string) config('services.garage.endpoint'),
            'region' => (string) config('services.garage.region', 'johnny'),
            'chunkBytes' => $this->uploadChunkBytes(),
            'shareLifetimes' => ObjectController::SHARE_LIFETIMES,
        ]);
    }

    /**
     * How large the pieces of a browser upload may be: under PHP's own limits (upload_max_filesize,
     * post_max_size), so uploads work on a stock php.ini, and at most 8 MB.
     */
    private function uploadChunkBytes(): int
    {
        $limits = array_filter([
            Format::parseBytes((string) ini_get('upload_max_filesize')),
            Format::parseBytes((string) ini_get('post_max_size')),
        ]);
        $limit = $limits === [] ? 8 * 1024 * 1024 + 262144 : min($limits);

        return (int) max(262144, min(8 * 1024 * 1024, $limit - 262144));
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

    /**
     * Create an access key that can only use this bucket, with the chosen scope.
     */
    public function storeKey(Request $request, string $bucket): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:128', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/'],
            'scope' => ['required', Rule::in(KeyScopes::ids())],
        ]);

        if (in_array($validated['name'], JohnnyCliService::SYSTEM_KEY_NAMES, true)) {
            return back()->withErrors(['name' => 'This name is reserved for the system.'])->withInput();
        }

        if (! in_array($bucket, $this->johnny->listAllBucketNamesFromGarage(), true)) {
            return back()->withErrors(['name' => 'Bucket not found.'])->withInput();
        }

        $result = $this->johnny->createKey($validated['name']);

        if (! $result->successful()) {
            return back()->withErrors(['name' => $this->johnny->errorDetail($result, 'Key creation failed.')])->withInput();
        }

        $credentials = $this->johnny->parseKeyCreateOutput($result->output());
        // Without a readable id, Garage still finds the new key by its name.
        $grant = $this->johnny->bucketAllow(
            $bucket,
            $credentials['access_key_id'] ?? $validated['name'],
            ...KeyScopes::flags($validated['scope']),
        );

        $redirect = redirect()->route('buckets.show', ['bucket' => $bucket, 'tab' => 'keys'])
            ->with('new_key', [
                'name' => $validated['name'],
                'credentials' => $credentials,
                'raw' => $result->output(),
                'bucket' => $bucket,
            ]);

        return $grant->successful()
            ? $redirect->with('status', 'Key created: '.strtolower(KeyScopes::label($validated['scope']))." on {$bucket}.")
            : $redirect->withErrors(['name' => $this->johnny->errorDetail($grant, 'The key was created, but access to this bucket could not be granted.')]);
    }

    public function allow(Request $request, string $bucket): RedirectResponse
    {
        if ($request->filled('scope')) {
            return $this->allowScope($request, $bucket);
        }

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
     * Give a key exactly one scope on the bucket, replacing whatever it had there.
     */
    private function allowScope(Request $request, string $bucket): RedirectResponse
    {
        $validated = $request->validate([
            'key_id' => ['required', 'string', 'max:128'],
            'scope' => ['required', Rule::in(KeyScopes::ids())],
        ]);

        $keyId = $this->johnny->resolveCanonicalKeyId($validated['key_id']);

        if ($keyId === null) {
            return back()->withErrors(['key_id' => 'Key not found.'])->withInput();
        }

        if ($this->johnny->isSystemKey($keyId)) {
            return back()->withErrors(['key_id' => 'System key permissions are managed by Johnny and cannot be changed.']);
        }

        $result = $this->johnny->setKeyScope($bucket, $keyId, $validated['scope']);

        if (! $result->successful()) {
            return back()->withErrors(['key_id' => $this->johnny->errorDetail($result, 'Granting permissions failed.')])->withInput();
        }

        return redirect()->route('buckets.show', ['bucket' => $bucket, 'tab' => 'keys'])
            ->with('status', KeyScopes::label($validated['scope']).' access granted.');
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
