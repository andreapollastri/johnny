<?php

namespace App\Models;

use App\Support\Format;
use App\Support\StorageProviders;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One import (remote bucket → Johnny) or export (Johnny → remote bucket), run by rclone.
 */
#[Fillable([
    'remote_connection_id', 'user_id', 'sync_id', 'connection_name', 'provider', 'direction', 'mode',
    'local_bucket', 'remote_bucket', 'prefix', 'dry_run', 'create_bucket', 'status', 'message',
])]
class Transfer extends Model
{
    public const IMPORT = 'import';

    public const EXPORT = 'export';

    public const COPY = 'copy';

    public const SYNC = 'sync';

    public const PENDING = 'pending';

    public const RUNNING = 'running';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    /** A running transfer that has not reported for this long is taken for dead. */
    public const STALE_AFTER_SECONDS = 180;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dry_run' => 'boolean',
            'create_bucket' => 'boolean',
            'bytes_done' => 'integer',
            'bytes_total' => 'integer',
            'files_done' => 'integer',
            'files_total' => 'integer',
            'checks_done' => 'integer',
            'errors' => 'integer',
            'speed' => 'integer',
            'eta' => 'integer',
            'log' => 'array',
            'launched_at' => 'datetime',
            'started_at' => 'datetime',
            'heartbeat_at' => 'datetime',
            'cancel_requested_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<RemoteConnection, $this>
     */
    public function remoteConnection(): BelongsTo
    {
        return $this->belongsTo(RemoteConnection::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The scheduled sync this run belongs to, when it is not a one-off transfer.
     *
     * @return BelongsTo<Sync, $this>
     */
    public function sync(): BelongsTo
    {
        return $this->belongsTo(Sync::class);
    }

    /**
     * Waiting for its turn or running.
     *
     * @param  Builder<Transfer>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', [self::PENDING, self::RUNNING]);
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::PENDING, self::RUNNING], true);
    }

    public function isImport(): bool
    {
        return $this->direction === self::IMPORT;
    }

    public function providerLabel(): string
    {
        return StorageProviders::label($this->provider);
    }

    /**
     * "media/photos/" — a bucket and the folder inside it, when the transfer is limited to one.
     */
    public function path(string $bucket): string
    {
        return $this->prefix === '' ? $bucket : $bucket.'/'.$this->prefix;
    }

    /**
     * Where the files come from, as shown in lists: "Hetzner backup: media" or "Johnny: media".
     */
    public function sourceLabel(): string
    {
        return $this->isImport()
            ? $this->connection_name.': '.$this->path($this->remote_bucket)
            : 'Johnny: '.$this->path($this->local_bucket);
    }

    public function destinationLabel(): string
    {
        return $this->isImport()
            ? 'Johnny: '.$this->path($this->local_bucket)
            : $this->connection_name.': '.$this->path($this->remote_bucket);
    }

    /**
     * 0–100 by bytes (by files when sizes are not known yet); null before rclone reports anything.
     */
    public function percent(): ?float
    {
        if ($this->status === self::COMPLETED) {
            return 100.0;
        }

        if ($this->bytes_total > 0) {
            return min(100.0, round($this->bytes_done / $this->bytes_total * 100, 1));
        }

        if ($this->files_total > 0) {
            return min(100.0, round($this->files_done / $this->files_total * 100, 1));
        }

        return null;
    }

    public function statusLabel(): string
    {
        if ($this->status === self::RUNNING && $this->cancel_requested_at !== null) {
            return 'cancelling';
        }

        return match ($this->status) {
            self::PENDING => 'waiting',
            self::RUNNING => 'running',
            self::COMPLETED => $this->dry_run ? 'dry run done' : 'completed',
            self::FAILED => 'failed',
            self::CANCELLED => 'cancelled',
            default => (string) $this->status,
        };
    }

    /**
     * "1.20 GB of 3.50 GB, 420 of 1,300 files".
     */
    public function progressLine(): string
    {
        $bytes = $this->bytes_total > 0
            ? Format::bytes($this->bytes_done).' of '.Format::bytes($this->bytes_total)
            : Format::bytes($this->bytes_done);
        $files = $this->files_total > 0
            ? number_format($this->files_done).' of '.number_format($this->files_total).' files'
            : number_format($this->files_done).' files';

        return $bytes.', '.$files;
    }

    /**
     * What the pages poll while a transfer runs.
     *
     * @return array<string, mixed>
     */
    public function toStatus(bool $withLog = false): array
    {
        $status = [
            'id' => $this->id,
            'status' => $this->status,
            'label' => $this->statusLabel(),
            'active' => $this->isActive(),
            'percent' => $this->percent(),
            'progress' => $this->progressLine(),
            'speed' => $this->status === self::RUNNING && $this->speed > 0 ? Format::bytes($this->speed).'/s' : null,
            'eta' => $this->status === self::RUNNING && $this->eta !== null ? Format::duration($this->eta) : null,
            'errors' => $this->errors,
            'message' => $this->message,
        ];

        if ($withLog) {
            $status['log'] = array_values((array) $this->log);
        }

        return $status;
    }

    /**
     * The transfer as the API returns it: its settings, its state and its counters.
     *
     * @return array<string, mixed>
     */
    public function toApi(bool $withLog = false): array
    {
        return [
            'id' => $this->id,
            'sync_id' => $this->sync_id,
            'connection_id' => $this->remote_connection_id,
            'connection_name' => $this->connection_name,
            'provider' => $this->provider,
            'direction' => $this->direction,
            'mode' => $this->mode,
            'local_bucket' => $this->local_bucket,
            'remote_bucket' => $this->remote_bucket,
            'prefix' => $this->prefix,
            'dry_run' => (bool) $this->dry_run,
            'create_bucket' => (bool) $this->create_bucket,
            'status' => $this->status,
            'active' => $this->isActive(),
            'cancelling' => $this->status === self::RUNNING && $this->cancel_requested_at !== null,
            'percent' => $this->percent(),
            'bytes_done' => (int) $this->bytes_done,
            'bytes_total' => (int) $this->bytes_total,
            'files_done' => (int) $this->files_done,
            'files_total' => (int) $this->files_total,
            'errors' => (int) $this->errors,
            'speed' => (int) $this->speed,
            'eta' => $this->eta,
            'message' => $this->message,
            'created_at' => $this->created_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
        ] + ($withLog ? ['log' => $this->log ?? []] : []);
    }
}
