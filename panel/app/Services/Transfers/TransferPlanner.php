<?php

namespace App\Services\Transfers;

use App\Models\RemoteConnection;
use App\Models\Sync;
use App\Models\Transfer;
use App\Services\JohnnyCliService;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Creates transfers and scheduled syncs, the same way for the panel's forms and for the API.
 */
class TransferPlanner
{
    public const LOCAL_BUCKET = '/^[a-z0-9][a-z0-9._-]{1,254}$/';

    public const REMOTE_BUCKET = '/^[A-Za-z0-9][A-Za-z0-9._-]{1,254}$/';

    /** A bucket name, or a folder of an SFTP server ("backups/media", "/srv/backups"). */
    public const REMOTE_LOCATION = '/^[A-Za-z0-9._~\/-]{1,255}$/';

    public function __construct(
        private JohnnyCliService $johnny,
        private TransferLauncher $launcher,
        private SyncScheduler $scheduler,
    ) {}

    /**
     * The fields a transfer and a sync have in common.
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'direction' => ['required', Rule::in([Transfer::IMPORT, Transfer::EXPORT])],
            'connection_id' => ['required', 'integer', Rule::exists('remote_connections', 'id')],
            'remote_bucket' => ['required', 'string', 'regex:'.self::REMOTE_LOCATION],
            'local_bucket' => ['required', 'string', 'regex:'.self::LOCAL_BUCKET],
            'prefix' => ['nullable', 'string', 'max:1024'],
            'mode' => ['required', Rule::in([Transfer::COPY, Transfer::SYNC])],
            'create_bucket' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'remote_bucket.regex' => 'That is not a bucket or folder name.',
            'local_bucket.regex' => 'Bucket names on Johnny use lowercase letters, digits, dots and dashes.',
        ];
    }

    /**
     * Where files are read from or written to on the other side, checked against what the
     * connection is: a bucket name for an S3 account, a folder path for an SFTP server.
     *
     * @throws ValidationException
     */
    public function remoteLocation(RemoteConnection $connection, string $location): string
    {
        if (! $connection->isSftp()) {
            if (! preg_match(self::REMOTE_BUCKET, $location)) {
                throw ValidationException::withMessages(['remote_bucket' => 'That is not a bucket name.']);
            }

            return $location;
        }

        $path = (string) preg_replace('#/{2,}#', '/', $location);
        $path = $path === '/' ? '/' : rtrim($path, '/');

        if ($path === '' || $path === '/' || $path === '~' || in_array('..', explode('/', $path), true)) {
            throw ValidationException::withMessages(['remote_bucket' => 'Type a folder of the server, for example backups/media.']);
        }

        return $path;
    }

    /**
     * The bucket on Johnny must be there before anything runs: an import (or a sync in) creates
     * it like the Buckets page does, an export (or a sync out) needs it to exist.
     *
     * @throws ValidationException
     */
    public function prepareLocalBucket(string $direction, string $bucket): void
    {
        if ($direction === Transfer::IMPORT) {
            if ($error = $this->johnny->ensureBucket($bucket)) {
                throw ValidationException::withMessages(['local_bucket' => $error]);
            }

            return;
        }

        if (! in_array($bucket, $this->johnny->listAllBucketNamesFromGarage(), true)) {
            throw ValidationException::withMessages(['local_bucket' => 'This bucket does not exist on Johnny.']);
        }
    }

    /**
     * Queue a one-off transfer and start it if a slot is free.
     *
     * @param  array<string, mixed>  $fields  Validated with rules(), plus an optional `dry_run`.
     */
    public function transfer(RemoteConnection $connection, array $fields, ?int $userId): Transfer
    {
        $transfer = $this->queue($connection, [
            'direction' => $fields['direction'],
            'mode' => $fields['mode'],
            'local_bucket' => $fields['local_bucket'],
            'remote_bucket' => $fields['remote_bucket'],
            'prefix' => self::prefix((string) ($fields['prefix'] ?? '')),
            'dry_run' => (bool) ($fields['dry_run'] ?? false),
            'create_bucket' => $fields['direction'] === Transfer::EXPORT && (bool) ($fields['create_bucket'] ?? false),
        ], $userId);

        $this->launcher->drain();

        return $transfer;
    }

    /**
     * Create a scheduled sync and start its first run.
     *
     * @param  array<string, mixed>  $fields  Validated with rules().
     */
    public function sync(RemoteConnection $connection, array $fields, int $intervalMinutes, ?int $userId): Sync
    {
        $sync = Sync::query()->create([
            'remote_connection_id' => $connection->id,
            'user_id' => $userId,
            'direction' => $fields['direction'],
            'mode' => $fields['mode'],
            'local_bucket' => $fields['local_bucket'],
            'remote_bucket' => $fields['remote_bucket'],
            'prefix' => self::prefix((string) ($fields['prefix'] ?? '')),
            'interval_minutes' => $intervalMinutes,
            'enabled' => true,
        ]);

        $this->scheduler->start($sync, (bool) ($fields['create_bucket'] ?? false));
        $this->launcher->drain();

        return $sync;
    }

    /**
     * Run a finished transfer again with the same settings; rclone skips what is already there.
     * `$forReal` turns a dry run into a real one. Null while it is still going, or when its
     * connection was deleted.
     */
    public function again(Transfer $transfer, bool $forReal, ?int $userId): ?Transfer
    {
        $connection = $transfer->remoteConnection;

        if ($transfer->isActive() || $connection === null) {
            return null;
        }

        $again = $this->queue($connection, [
            'direction' => $transfer->direction,
            'mode' => $transfer->mode,
            'local_bucket' => $transfer->local_bucket,
            'remote_bucket' => $transfer->remote_bucket,
            'prefix' => $transfer->prefix,
            'dry_run' => $transfer->dry_run && ! $forReal,
            'create_bucket' => $transfer->create_bucket,
        ], $userId);

        $this->launcher->drain();

        return $again;
    }

    /**
     * Stop a transfer: at once when it is waiting, by asking its process when it is running (the
     * runner checks every couple of seconds and stops rclone). Finished transfers are left alone.
     */
    public function cancel(Transfer $transfer): void
    {
        if ($transfer->status === Transfer::PENDING) {
            $transfer->forceFill([
                'status' => Transfer::CANCELLED,
                'message' => 'Cancelled before it started.',
                'finished_at' => now(),
            ])->save();
        } elseif ($transfer->status === Transfer::RUNNING) {
            $transfer->forceFill(['cancel_requested_at' => now()])->save();
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function queue(RemoteConnection $connection, array $attributes, ?int $userId): Transfer
    {
        return Transfer::query()->create([
            ...$attributes,
            'remote_connection_id' => $connection->id,
            'user_id' => $userId,
            'connection_name' => $connection->name,
            'provider' => $connection->provider,
            'status' => Transfer::PENDING,
        ]);
    }

    /**
     * "photos/2026" and "/photos/2026/" both mean the folder "photos/2026/"; empty is the bucket.
     */
    public static function prefix(string $prefix): string
    {
        $prefix = trim($prefix, '/');

        return $prefix === '' ? '' : $prefix.'/';
    }
}
