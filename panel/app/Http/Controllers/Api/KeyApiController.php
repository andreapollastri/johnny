<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\JohnnyCliService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class KeyApiController extends Controller
{
    public function __construct(
        private JohnnyCliService $johnny
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

        $keys = collect($this->johnny->parseKeyList($output))
            ->reject(fn ($k) => in_array($k['name'], JohnnyCliService::SYSTEM_KEY_NAMES, true))
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        return response()->json(['data' => $keys]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:128', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/'],
        ]);

        if (in_array($validated['name'], JohnnyCliService::SYSTEM_KEY_NAMES, true)) {
            return response()->json([
                'message' => 'This name is reserved for the system.',
            ], 422);
        }

        $result = $this->johnny->runJohnnyResult(['key', 'create', $validated['name']]);
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

        return response()->json([
            'id' => $parsed['access_key_id'],
            'name' => $validated['name'],
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

                return response()->json(['data' => $k]);
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

        $result = $this->johnny->runJohnnyResult(['key', 'delete', '--yes', $canonical]);
        if (! $result->successful()) {
            $detail = trim($result->errorOutput() ?: $result->output());

            return response()->json([
                'message' => 'Failed to delete key.',
                'detail' => $detail !== '' ? $detail : 'Unknown error from johnny key delete.',
            ], 422);
        }

        return response()->noContent();
    }
}
