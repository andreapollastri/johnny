<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\JohnnyCliService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class BucketKeyApiController extends Controller
{
    public function __construct(
        private JohnnyCliService $johnny
    ) {}

    /**
     * Grant permissions for a Garage key on a bucket (same as the panel “Grant access” form).
     */
    public function store(Request $request, string $bucket): JsonResponse|Response
    {
        $validated = $request->validate([
            'key_id' => ['required', 'string', 'max:128'],
            'read' => ['nullable', 'boolean'],
            'write' => ['nullable', 'boolean'],
            'owner' => ['nullable', 'boolean'],
        ]);

        if (! $request->boolean('read') && ! $request->boolean('write') && ! $request->boolean('owner')) {
            return response()->json([
                'message' => 'Select at least one permission (read, write, owner).',
            ], 422);
        }

        $result = $this->johnny->bucketAllow(
            $bucket,
            $validated['key_id'],
            $request->boolean('read'),
            $request->boolean('write'),
            $request->boolean('owner'),
        );

        if (! $result->successful()) {
            $detail = trim($result->errorOutput() ?: $result->output());

            return response()->json([
                'message' => 'Failed to grant permissions.',
                'detail' => $detail !== '' ? $detail : 'Unknown error from johnny bucket allow.',
            ], 422);
        }

        return response()->json([
            'bucket' => $bucket,
            'key_id' => $validated['key_id'],
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

        $result = $this->johnny->bucketDeny(
            $bucket,
            $canonical,
            $request->boolean('read'),
            $request->boolean('write'),
            $request->boolean('owner'),
        );

        if (! $result->successful()) {
            $detail = trim($result->errorOutput() ?: $result->output());

            return response()->json([
                'message' => 'Failed to revoke permissions.',
                'detail' => $detail !== '' ? $detail : 'Unknown error from johnny bucket deny.',
            ], 422);
        }

        return response()->noContent();
    }
}
