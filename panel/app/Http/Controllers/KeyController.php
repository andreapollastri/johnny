<?php

namespace App\Http\Controllers;

use App\Services\GlobalKeys;
use App\Services\JohnnyCliService;
use App\Support\KeyScopes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class KeyController extends Controller
{
    /** Where a new key gets access: nowhere yet, every bucket, or the buckets picked in the form. */
    public const ACCESS = ['none', 'all', 'buckets'];

    public function __construct(
        private JohnnyCliService $johnny,
        private GlobalKeys $globalKeys,
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
            'global' => $this->globalKeys->all(),
            'buckets' => $this->johnny->listAllBucketNamesFromGarage(),
            'scopes' => KeyScopes::all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:128', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/'],
            'access' => ['nullable', Rule::in(self::ACCESS)],
            'scope' => ['nullable', Rule::in(KeyScopes::ids())],
            'buckets' => ['nullable', 'array'],
            'buckets.*' => ['string', 'max:255'],
        ]);

        if (in_array($validated['name'], JohnnyCliService::SYSTEM_KEY_NAMES, true)) {
            return back()->withErrors(['name' => 'This name is reserved for the system.'])->withInput();
        }

        $access = $validated['access'] ?? 'none';
        $scope = $validated['scope'] ?? KeyScopes::READ_WRITE;
        $buckets = [];

        if ($access === 'buckets') {
            $buckets = array_values(array_intersect(
                $this->johnny->listAllBucketNamesFromGarage(),
                $validated['buckets'] ?? [],
            ));

            if ($buckets === []) {
                return back()->withErrors(['buckets' => 'Pick at least one bucket for this key.'])->withInput();
            }
        }

        $result = $this->johnny->createKey($validated['name']);

        if (! $result->successful()) {
            return back()->withErrors(['name' => $this->johnny->errorDetail($result, 'Key creation failed.')])->withInput();
        }

        $credentials = $this->johnny->parseKeyCreateOutput($result->output());
        $status = 'Key created. Grant bucket permissions from the bucket detail page.';
        $failed = [];

        if ($credentials !== null && $access === 'all') {
            $outcome = $this->globalKeys->enable($credentials['access_key_id'], $validated['name'], $scope, fresh: true);
            $failed = $outcome['failed'];
            $status = 'Key created: '.strtolower(KeyScopes::label($scope)).' on all buckets, including the ones you create later.';
        }

        if ($credentials !== null && $access === 'buckets') {
            foreach ($buckets as $bucket) {
                if (! $this->johnny->bucketAllow($bucket, $credentials['access_key_id'], ...KeyScopes::flags($scope))->successful()) {
                    $failed[] = $bucket;
                }
            }

            $status = 'Key created: '.strtolower(KeyScopes::label($scope)).' on '.implode(', ', $buckets).'.';
        }

        if ($credentials === null && $access !== 'none') {
            $status = 'Key created, but its ID could not be read from Garage: open the key and set its access there.';
        }

        $redirect = redirect()->route('keys.index')
            ->with('new_key', [
                'name' => $validated['name'],
                'credentials' => $credentials,
                'raw' => $result->output(),
                'bucket' => $buckets[0] ?? null,
            ])
            ->with('status', $status);

        return $failed === []
            ? $redirect
            : $redirect->withErrors(['buckets' => 'Access could not be granted on: '.implode(', ', $failed).'.']);
    }

    public function show(string $keyId): View
    {
        $key = $this->userKey($keyId);
        $info = $this->johnny->keyInfo($key['id']);

        return view('keys.show', [
            'key' => $key,
            'global' => $this->globalKeys->find($key['id']),
            'grants' => $info['buckets'],
            'infoKnown' => $info['known'],
            'buckets' => $this->johnny->listAllBucketNamesFromGarage(),
            'scopes' => KeyScopes::all(),
        ]);
    }

    /**
     * Turn "all buckets" on (with a scope), change its scope, or turn it off.
     */
    public function allBuckets(Request $request, string $keyId): RedirectResponse
    {
        $key = $this->userKey($keyId);

        $validated = $request->validate([
            'scope' => ['required', Rule::in([...KeyScopes::ids(), 'off'])],
        ]);

        if ($validated['scope'] === 'off') {
            $this->globalKeys->disable($key['id']);

            return redirect()->route('keys.show', $key['id'])
                ->with('status', 'This key no longer follows new buckets. The access it has now is unchanged.');
        }

        $outcome = $this->globalKeys->enable($key['id'], $key['name'], $validated['scope']);
        $redirect = redirect()->route('keys.show', $key['id'])
            ->with('status', KeyScopes::label($validated['scope']).' on all buckets ('.$outcome['buckets'].' now, new ones as they are created).');

        return $outcome['failed'] === []
            ? $redirect
            : $redirect->withErrors(['scope' => 'Access could not be set on: '.implode(', ', $outcome['failed']).'.']);
    }

    /**
     * Set what the key may do in one bucket, or revoke it there (scope "none").
     */
    public function grant(Request $request, string $keyId): RedirectResponse
    {
        $key = $this->userKey($keyId);

        $validated = $request->validate([
            'bucket' => ['required', 'string', 'max:255'],
            'scope' => ['required', Rule::in([...KeyScopes::ids(), 'none'])],
        ]);

        if (! in_array($validated['bucket'], $this->johnny->listAllBucketNamesFromGarage(), true)) {
            return back()->withErrors(['bucket' => 'Bucket not found.']);
        }

        $result = $validated['scope'] === 'none'
            ? $this->johnny->bucketDeny($validated['bucket'], $key['id'], true, true, true)
            : $this->johnny->setKeyScope($validated['bucket'], $key['id'], $validated['scope']);

        if (! $result->successful()) {
            return back()->withErrors(['bucket' => $this->johnny->errorDetail($result, 'Changing the access failed.')]);
        }

        return redirect()->route('keys.show', $key['id'])->with('status', $validated['scope'] === 'none'
            ? "Access to {$validated['bucket']} revoked."
            : KeyScopes::label($validated['scope'])." on {$validated['bucket']}.");
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

    /**
     * A key created by a user, by exact id: Garage also matches id prefixes, and the keys Johnny
     * uses itself are not managed here.
     *
     * @return array{id: string, name: string, created: ?string}
     */
    private function userKey(string $keyId): array
    {
        foreach ($this->johnny->userKeys($this->johnny->listAllKeys()) as $key) {
            if (strcasecmp($key['id'], $keyId) === 0 && ! $this->johnny->isSystemKey($key['id'])) {
                return $key;
            }
        }

        abort(404);
    }
}
