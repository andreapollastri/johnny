<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\KeyController;
use App\Services\GlobalKeys;
use App\Services\JohnnyCliService;
use App\Support\KeyScopes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class KeyApiController extends Controller
{
    public function __construct(
        private JohnnyCliService $johnny,
        private GlobalKeys $globalKeys,
    ) {}

    public function index(): JsonResponse
    {
        $output = $this->johnny->runJohnny(['key', 'list']);
        if ($output === null) {
            return response()->json([
                'message' => 'Could not run `johnny key list`.',
                'detail' => 'Ensure sudoers allows the PHP user to run `sudo -u johnny /usr/local/bin/johnny`.',
            ], 503);
        }

        $global = $this->globalKeys->all();

        return response()->json(['data' => array_map(
            fn (array $key) => $key + [
                'all_buckets' => isset($global[$key['id']]),
                'scope' => isset($global[$key['id']]) ? $global[$key['id']]->scope : null,
            ],
            $this->johnny->userKeys($this->johnny->parseKeyList($output)),
        )]);
    }

    /**
     * Create a key and, in the same call, say where it works: nowhere yet (`access: none`, the
     * default), on every bucket (`all`) or on the listed ones (`buckets`), with one scope.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:128', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/'],
            'access' => ['nullable', Rule::in(KeyController::ACCESS)],
            'scope' => ['nullable', Rule::in(KeyScopes::ids())],
            'buckets' => ['nullable', 'array'],
            'buckets.*' => ['string', 'max:255'],
        ]);

        if (in_array($validated['name'], JohnnyCliService::SYSTEM_KEY_NAMES, true)) {
            return response()->json([
                'message' => 'This name is reserved for the system.',
            ], 422);
        }

        $access = $validated['access'] ?? 'none';
        $scope = $validated['scope'] ?? KeyScopes::READ_WRITE;
        $buckets = [];

        if ($access === 'buckets') {
            $wanted = array_values(array_unique($validated['buckets'] ?? []));
            $buckets = array_values(array_intersect($this->johnny->listAllBucketNamesFromGarage(), $wanted));

            if ($wanted === [] || count($buckets) !== count($wanted)) {
                return response()->json([
                    'message' => $wanted === [] ? 'List at least one bucket for this key.' : 'Some buckets do not exist.',
                    'detail' => implode(', ', array_diff($wanted, $buckets)) ?: null,
                ], 422);
            }
        }

        $result = $this->johnny->createKey($validated['name']);
        $combined = trim($result->output()."\n".$result->errorOutput());

        if (! $result->successful()) {
            return response()->json([
                'message' => 'Failed to create key.',
                'detail' => $combined !== '' ? $combined : 'Unknown error from johnny key create.',
            ], 422);
        }

        $parsed = $this->johnny->parseKeyCreateOutput($combined);
        if ($parsed === null) {
            return response()->json([
                'message' => 'Key was created but credentials could not be parsed.',
                'name' => $validated['name'],
                'raw_output' => $combined,
            ], 201);
        }

        $failed = [];

        if ($access === 'all') {
            $failed = $this->globalKeys->enable($parsed['access_key_id'], $validated['name'], $scope, fresh: true)['failed'];
        }

        foreach ($buckets as $bucket) {
            if (! $this->johnny->bucketAllow($bucket, $parsed['access_key_id'], ...KeyScopes::flags($scope))->successful()) {
                $failed[] = $bucket;
            }
        }

        return response()->json([
            'id' => $parsed['access_key_id'],
            'name' => $validated['name'],
            'access' => $access,
            'scope' => $access === 'none' ? null : $scope,
            'buckets' => $buckets,
            'failed_buckets' => $failed,
            'credentials' => [
                'access_key_id' => $parsed['access_key_id'],
                'secret_access_key' => $parsed['secret_access_key'],
            ],
            'raw_output' => $combined,
        ], 201);
    }

    public function show(string $keyId): JsonResponse
    {
        $output = $this->johnny->runJohnny(['key', 'list']);
        if ($output === null) {
            return response()->json([
                'message' => 'Could not run `johnny key list`.',
            ], 503);
        }

        foreach ($this->johnny->parseKeyList($output) as $k) {
            if (strcasecmp($k['id'], $keyId) === 0) {
                if ($this->johnny->isSystemKey($k['id'])) {
                    return response()->json(['message' => 'System keys are not exposed via this API.'], 403);
                }

                $global = $this->globalKeys->find($k['id']);

                return response()->json(['data' => $k + [
                    'all_buckets' => $global !== null,
                    'scope' => $global?->scope,
                    'buckets' => array_map(fn (array $grant) => $grant + [
                        'scope' => KeyScopes::fromPermissions($grant['permissions']),
                    ], $this->johnny->keyInfo($k['id'])['buckets']),
                ]]);
            }
        }

        return response()->json(['message' => 'Key not found.'], 404);
    }

    /**
     * Give the key one scope on every bucket, the ones created later included.
     */
    public function allBuckets(Request $request, string $keyId): JsonResponse
    {
        $validated = $request->validate([
            'scope' => ['required', Rule::in(KeyScopes::ids())],
        ]);

        $key = $this->userKey($keyId);
        if ($key instanceof JsonResponse) {
            return $key;
        }

        $outcome = $this->globalKeys->enable($key['id'], $key['name'], $validated['scope']);

        return response()->json(['data' => [
            'id' => $key['id'],
            'all_buckets' => true,
            'scope' => $validated['scope'],
            'buckets' => $outcome['buckets'],
            'failed_buckets' => $outcome['failed'],
        ]]);
    }

    /**
     * Stop following new buckets. The access the key has now stays as it is.
     */
    public function forgetAllBuckets(string $keyId): JsonResponse|Response
    {
        $key = $this->userKey($keyId);
        if ($key instanceof JsonResponse) {
            return $key;
        }

        $this->globalKeys->disable($key['id']);

        return response()->noContent();
    }

    /**
     * A key by exact id (Garage also matches prefixes), or the error to answer with.
     *
     * @return array{id: string, name: string, created: ?string}|JsonResponse
     */
    private function userKey(string $keyId): array|JsonResponse
    {
        foreach ($this->johnny->listAllKeys() as $key) {
            if (strcasecmp($key['id'], $keyId) === 0) {
                return $this->johnny->isSystemKey($key['id'])
                    ? response()->json(['message' => 'System keys are managed by Johnny.'], 403)
                    : $key;
            }
        }

        return response()->json(['message' => 'Key not found.'], 404);
    }

    public function destroy(string $keyId): JsonResponse|Response
    {
        $canonical = $this->johnny->resolveCanonicalKeyId($keyId);
        if ($canonical === null) {
            return response()->json(['message' => 'Key not found.'], 404);
        }

        if ($this->johnny->isSystemKey($canonical)) {
            return response()->json(['message' => 'System keys cannot be deleted.'], 403);
        }

        $result = $this->johnny->deleteKey($canonical);
        if (! $result->successful()) {
            return response()->json([
                'message' => 'Failed to delete key.',
                'detail' => $this->johnny->errorDetail($result, 'Unknown error from johnny key delete.'),
            ], 422);
        }

        return response()->noContent();
    }
}
