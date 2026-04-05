<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GarageS3;
use App\Services\JohnnyCliService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class BucketApiController extends Controller
{
    public function __construct(
        private GarageS3 $garage,
        private JohnnyCliService $johnny
    ) {}

    public function index(): JsonResponse
    {
        try {
            $buckets = $this->garage->listBuckets();
        } catch (\Throwable $e) {
            $msg = str_contains($e->getMessage(), 'AccessDenied')
                ? 'The panel S3 key does not have permission to list buckets. Check GARAGE_* credentials in panel/.env.'
                : $e->getMessage();

            return response()->json([
                'message' => 'Could not list buckets.',
                'detail' => $msg,
            ], 503);
        }

        return response()->json(['data' => $buckets]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9][a-z0-9._-]{1,254}$/'],
        ]);

        $name = $validated['name'];

        $result = $this->johnny->runJohnnyResult(['bucket', 'create', $name]);
        if (! $result->successful()) {
            $detail = trim($result->errorOutput() ?: $result->output());

            return response()->json([
                'message' => 'Failed to create bucket.',
                'detail' => $detail !== '' ? $detail : 'Unknown error from johnny bucket create.',
            ], 422);
        }

        $this->johnny->allowKeyOnBucket(config('services.garage.key_name', 'johnny-default'), $name);

        return response()->json([
            'data' => [
                'name' => $name,
            ],
        ], 201);
    }

    public function show(string $bucket): JsonResponse
    {
        try {
            $buckets = $this->garage->listBuckets();
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Could not list buckets.',
                'detail' => $e->getMessage(),
            ], 503);
        }

        $meta = null;
        foreach ($buckets as $b) {
            if ($b['name'] === $bucket) {
                $meta = $b;
                break;
            }
        }

        if ($meta === null) {
            return response()->json(['message' => 'Bucket not found.'], 404);
        }

        [$keys, $raw] = $this->johnny->parseBucketInfo($bucket);

        return response()->json([
            'data' => [
                'name' => $meta['name'],
                'created' => $meta['created'],
                'keys' => $keys,
                'info_raw' => $raw !== '' ? $raw : null,
            ],
        ]);
    }

    public function destroy(string $bucket): JsonResponse|Response
    {
        if (in_array($bucket, JohnnyCliService::PROTECTED_BUCKETS, true)) {
            return response()->json([
                'message' => "The \"{$bucket}\" bucket is protected and cannot be deleted.",
            ], 403);
        }

        $result = $this->johnny->runJohnnyResult(['bucket', 'delete', '--yes', $bucket]);
        if (! $result->successful()) {
            $detail = trim($result->errorOutput() ?: $result->output());

            return response()->json([
                'message' => 'Failed to delete bucket.',
                'detail' => $detail !== '' ? $detail : 'Unknown error from johnny bucket delete.',
            ], 422);
        }

        return response()->noContent();
    }
}
