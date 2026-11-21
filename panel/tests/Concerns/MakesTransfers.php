<?php

namespace Tests\Concerns;

use App\Models\RemoteConnection;
use App\Models\Sync;
use App\Models\Transfer;

/**
 * Rows for the Transfers tests: a connection to another provider, transfers and syncs on it.
 */
trait MakesTransfers
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function makeConnection(array $attributes = []): RemoteConnection
    {
        return RemoteConnection::query()->create($attributes + [
            'name' => 'Spaces production',
            'provider' => 'spaces',
            'endpoint' => 'https://fra1.digitaloceanspaces.com',
            'region' => 'us-east-1',
            'access_key_id' => 'DO00EXAMPLEKEY',
            'secret_access_key' => 'remote-secret',
            'path_style' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes  Any column: status and counters included.
     */
    protected function makeTransfer(RemoteConnection $connection, array $attributes = []): Transfer
    {
        $transfer = new Transfer;
        $transfer->forceFill($attributes + [
            'remote_connection_id' => $connection->id,
            'connection_name' => $connection->name,
            'provider' => $connection->provider,
            'direction' => Transfer::IMPORT,
            'mode' => Transfer::COPY,
            'local_bucket' => 'media',
            'remote_bucket' => 'cernusco-media',
            'prefix' => '',
            'status' => Transfer::PENDING,
        ])->save();

        return $transfer;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function makeSync(RemoteConnection $connection, array $attributes = []): Sync
    {
        return Sync::query()->create($attributes + [
            'remote_connection_id' => $connection->id,
            'direction' => Transfer::EXPORT,
            'mode' => Transfer::COPY,
            'local_bucket' => 'media',
            'remote_bucket' => 'media-offsite',
            'prefix' => '',
            'interval_minutes' => 5,
            'enabled' => true,
            'next_run_at' => now()->subMinute(),
        ]);
    }
}
