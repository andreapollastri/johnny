<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\JohnnyCliService;
use App\Support\KeyScopes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class BucketKeyApiController extends Controller
{
    public function __construct(
        private JohnnyCliService $johnny
    ) {}

    /**
     * Grant a Garage key access to a bucket: a `scope` (exact), or read/write/owner flags (added to what
     * the key already has).
     */
    public function store(Request $request, string $bucket): JsonResponse|Response
    {
        $validated = $request->validate([
            'key_id' => ['required', 'string', 'max:128'],
            'scope' => ['nullable', Rule::in(KeyScopes::ids())],
            'read' => ['nullable', 'boolean'],
            'write' => ['nullable', 'boolean'],
            'owner' => ['nullable', 'boolean'],
        ]);

        $scope = $validated['scope'] ?? null;

        if ($scope === null && ! $request->boolean('read') && ! $request->boolean('write') && ! $request->boolean('owner')) {
            return response()->json([
                'message' => 'Pass a scope, or at least one permission (read, write, owner).',
            ], 422);
        }

        $canonical = $this->johnny->resolveCanonicalKeyId($validated['key_id']);
        if ($canonical === null) {
            return response()->json(['message' => 'Key not found.'], 404);
        }

        // A scope is exact: it grants its permissions and revokes the others.
        if ($scope !== null) {
            if ($this->johnny->isSystemKey($canonical)) {
                return response()->json(['message' => 'System key permissions are managed by Johnny and cannot be changed.'], 403);
            }

            $result = $this->johnny->setKeyScope($bucket, $canonical, $scope);

            if (! $result->successful()) {
                return response()->json([
                    'message' => 'Failed to grant permissions.',
                    'detail' => $this->johnny->errorDetail($result, 'Unknown error from johnny bucket allow.'),
                ], 422);
            }

            [$read, $write, $owner] = KeyScopes::flags($scope);

            return response()->json([
                'bucket' => $bucket,
                'key_id' => $canonical,
                'scope' => $scope,
                'read' => $read,
                'write' => $write,
                'owner' => $owner,
            ], 201);
        }

        $result = $this->johnny->bucketAllow(
            $bucket,
            $canonical,
            $request->boolean('read'),
            $request->boolean('write'),
            $request->boolean('owner'),
        );

        if (! $result->successful()) {
            return response()->json([
                'message' => 'Failed to grant permissions.',
                'detail' => $this->johnny->errorDetail($result, 'Unknown error from johnny bucket allow.'),
            ], 422);
        }

        return response()->json([
            'bucket' => $bucket,
            'key_id' => $canonical,
            'read' => $request->boolean('read'),
            'write' => $request->boolean('write'),
            'owner' => $request->boolean('owner'),
        ], 201);
    }

    /**
     * Revoke permissions (same semantics as the panel revoke form: pass which flags to remove).
     */
    public function destroy(Request $request, string $bucket, string $keyId): JsonResponse|Response
    {
        $validated = $request->validate([
            'read' => ['nullable', 'boolean'],
            'write' => ['nullable', 'boolean'],
            'owner' => ['nullable', 'boolean'],
        ]);

        if (! $request->boolean('read') && ! $request->boolean('write') && ! $request->boolean('owner')) {
            return response()->json([
                'message' => 'Select at least one permission to revoke (read, write, owner).',
            ], 422);
        }

        $canonical = $this->johnny->resolveCanonicalKeyId($keyId);
        if ($canonical === null) {
            return response()->json(['message' => 'Key not found.'], 404);
        }

        if ($this->johnny->isSystemKey($canonical)) {
            return response()->json(['message' => 'System key permissions are managed by Johnny and cannot be revoked.'], 403);
        }

        $result = $this->johnny->bucketDeny(
            $bucket,
            $canonical,
            $request->boolean('read'),
            $request->boolean('write'),
            $request->boolean('owner'),
        );

        if (! $result->successful()) {
            return response()->json([
                'message' => 'Failed to revoke permissions.',
                'detail' => $this->johnny->errorDetail($result, 'Unknown error from johnny bucket deny.'),
            ], 422);
        }

        return response()->noContent();
    }
}
