<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RemoteConnection;
use App\Models\Sync;
use App\Services\ConnectionInput;
use App\Services\RemoteS3;
use App\Support\StorageProviders;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Connections to other S3 providers: the accounts transfers and syncs read from or write to.
 * Secrets go in and never come back out.
 */
class ConnectionApiController extends Controller
{
    public function __construct(
        private RemoteS3 $remote
    ) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => RemoteConnection::query()->orderBy('name')->get()->map(fn (RemoteConnection $connection) => $connection->toApi())->values(),
        ]);
    }

    /**
     * The providers a connection can be made to, with what each needs.
     */
    public function providers(): JsonResponse
    {
        $providers = [];

        foreach (StorageProviders::all() as $id => $provider) {
            $providers[] = [
                'id' => $id,
                'label' => $provider['label'],
                'needs_endpoint' => $provider['needs_endpoint'],
                'endpoint_example' => $provider['endpoint'],
                'default_region' => $provider['default_region'],
                'path_style' => $provider['path_style'],
            ];
        }

        return response()->json(['data' => $providers]);
    }

    /**
     * Save a connection and try it. A connection the provider refuses is saved all the same,
     * with the reason in `check_error`.
     */
    public function store(Request $request): JsonResponse
    {
        $connection = RemoteConnection::query()->create(ConnectionInput::validate($request));
        $this->remote->check($connection);

        return response()->json(['data' => $connection->refresh()->toApi()], 201);
    }

    public function show(RemoteConnection $connection): JsonResponse
    {
        return response()->json(['data' => $connection->toApi()]);
    }

    /**
     * Change a connection. Leave `secret_access_key` out to keep the stored one.
     */
    public function update(Request $request, RemoteConnection $connection): JsonResponse
    {
        $connection->update(ConnectionInput::validate($request, $connection));
        $this->remote->check($connection);

        return response()->json(['data' => $connection->refresh()->toApi()]);
    }

    public function destroy(RemoteConnection $connection): JsonResponse|Response
    {
        if ($connection->transfers()->active()->exists()) {
            return response()->json(['message' => 'A transfer is using this connection. Cancel it or wait for it to finish.'], 409);
        }

        if (Sync::query()->where('remote_connection_id', $connection->id)->exists()) {
            return response()->json(['message' => 'A scheduled sync uses this connection. Delete the sync first.'], 409);
        }

        $connection->delete();

        return response()->noContent();
    }

    /**
     * Ask the provider whether the credentials still work.
     */
    public function check(RemoteConnection $connection): JsonResponse
    {
        $error = $this->remote->check($connection);

        return response()->json(['data' => ['id' => $connection->id, 'reachable' => $error === null, 'error' => $error]]);
    }

    /**
     * The buckets of the account, to pick what to import.
     */
    public function buckets(RemoteConnection $connection): JsonResponse
    {
        try {
            return response()->json(['data' => $this->remote->listBuckets($connection)]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => $this->remote->isAccessDenied($e)
                    ? 'This access key is not allowed to list buckets.'
                    : 'Could not list the buckets of the provider.',
                'detail' => $this->remote->describe($e),
            ], 502);
        }
    }
}
