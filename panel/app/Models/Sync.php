<?php

namespace App\Models;

use App\Support\Intervals;
use App\Support\StorageProviders;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A transfer on a schedule: every few minutes (or hours, or days) a bucket is synced in from
 * another provider, or out to it.
 *
 * @property int $id
 * @property int $remote_connection_id
 * @property string $direction
 * @property string $mode
 * @property string $local_bucket
 * @property string $remote_bucket
 * @property string $prefix
 * @property int $interval_minutes
 * @property bool $enabled
 * @property ?Carbon $last_run_at
 * @property ?Carbon $next_run_at
 */
#[Fillable([
    'remote_connection_id', 'user_id', 'direction', 'mode', 'local_bucket', 'remote_bucket',
    'prefix', 'interval_minutes', 'enabled', 'last_run_at', 'next_run_at',
])]
class Sync extends Model
{
    /** Finished runs kept for each sync: a sync that runs every minute makes 1,440 a day. */
    public const KEEP_RUNS = 30;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'interval_minutes' => 'integer',
            'enabled' => 'boolean',
            'last_run_at' => 'datetime',
            'next_run_at' => 'datetime',
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
     * @return HasMany<Transfer, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(Transfer::class);
    }

    /**
     * @return HasOne<Transfer, $this>
     */
    public function latestRun(): HasOne
    {
        return $this->hasOne(Transfer::class)->latestOfMany();
    }

    public function isImport(): bool
    {
        return $this->direction === Transfer::IMPORT;
    }

    public function isDue(): bool
    {
        return $this->enabled && ($this->next_run_at === null || $this->next_run_at->isPast());
    }

    public function intervalLabel(): string
    {
        return Intervals::label($this->interval_minutes);
    }

    public function connectionName(): string
    {
        return $this->remoteConnection?->name ?? 'Deleted connection';
    }

    public function providerLabel(): string
    {
        return StorageProviders::label($this->remoteConnection?->provider ?? 'other');
    }

    public function path(string $bucket): string
    {
        return $this->prefix === '' ? $bucket : $bucket.'/'.$this->prefix;
    }

    public function sourceLabel(): string
    {
        return $this->isImport()
            ? $this->connectionName().': '.$this->path($this->remote_bucket)
            : 'Johnny: '.$this->path($this->local_bucket);
    }

    public function destinationLabel(): string
    {
        return $this->isImport()
            ? 'Johnny: '.$this->path($this->local_bucket)
            : $this->connectionName().': '.$this->path($this->remote_bucket);
    }

    /**
     * "Sync in" brings the other provider's files here; "Sync out" sends ours there.
     */
    public function directionLabel(): string
    {
        return $this->isImport() ? 'In' : 'Out';
    }

    public function modeLabel(): string
    {
        return $this->mode === Transfer::SYNC ? 'Mirror' : 'Copy';
    }

    /**
     * The sync as the API returns it, with the state of its latest run.
     *
     * @return array<string, mixed>
     */
    public function toApi(): array
    {
        $latest = $this->latestRun;

        return [
            'id' => $this->id,
            'connection_id' => $this->remote_connection_id,
            'connection_name' => $this->connectionName(),
            'direction' => $this->direction,
            'mode' => $this->mode,
            'local_bucket' => $this->local_bucket,
            'remote_bucket' => $this->remote_bucket,
            'prefix' => $this->prefix,
            'interval_minutes' => $this->interval_minutes,
            'interval_label' => $this->intervalLabel(),
            'enabled' => $this->enabled,
            'last_run_at' => $this->last_run_at?->toIso8601String(),
            'next_run_at' => $this->next_run_at?->toIso8601String(),
            'latest_run' => $latest === null ? null : [
                'id' => $latest->id,
                'status' => $latest->status,
                'message' => $latest->message,
                'finished_at' => $latest->finished_at?->toIso8601String(),
            ],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
