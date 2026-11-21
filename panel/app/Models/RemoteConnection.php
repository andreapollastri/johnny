<?php

namespace App\Models;

use App\Support\StorageProviders;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Credentials for an S3 account somewhere else (AWS, R2, Spaces, another Johnny…), used to
 * import buckets from it and export buckets to it.
 */
#[Fillable(['name', 'provider', 'endpoint', 'region', 'access_key_id', 'secret_access_key', 'path_style', 'host_key', 'checked_at', 'check_error'])]
#[Hidden(['access_key_id', 'secret_access_key', 'host_key'])]
class RemoteConnection extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'access_key_id' => 'encrypted',
            'secret_access_key' => 'encrypted',
            'path_style' => 'boolean',
            'checked_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Transfer, $this>
     */
    public function transfers(): HasMany
    {
        return $this->hasMany(Transfer::class);
    }

    public function providerLabel(): string
    {
        return StorageProviders::label($this->provider);
    }

    /**
     * A server reached over SSH instead of an S3 account. Its address is kept in `endpoint` as
     * sftp://host:port, the user name in `access_key_id`, the password or private key in
     * `secret_access_key`.
     */
    public function isSftp(): bool
    {
        return StorageProviders::isSftp((string) $this->provider);
    }

    public function sftpHost(): string
    {
        return (string) parse_url((string) $this->endpoint, PHP_URL_HOST);
    }

    public function sftpPort(): int
    {
        return (int) (parse_url((string) $this->endpoint, PHP_URL_PORT) ?: 22);
    }

    public function usesPrivateKey(): bool
    {
        return str_starts_with(ltrim((string) $this->secret_access_key), '-----BEGIN');
    }

    /**
     * What the other side calls the place files go: a bucket, or a folder of an SFTP server.
     */
    public function containerWord(): string
    {
        return $this->isSftp() ? 'folder' : 'bucket';
    }

    /**
     * The region S3 requests are signed for: the one typed in, or the provider's default.
     */
    public function signingRegion(): string
    {
        return $this->region ?: StorageProviders::get($this->provider)['default_region'];
    }

    /**
     * Where the connection points, for lists: the endpoint host, or the AWS region.
     */
    public function location(): string
    {
        if ($this->endpoint) {
            return (string) (parse_url($this->endpoint, PHP_URL_HOST) ?: $this->endpoint);
        }

        return $this->signingRegion();
    }

    /**
     * The access key id with its middle hidden, e.g. "AKIA…9F2Q".
     */
    public function maskedKey(): string
    {
        $key = (string) $this->access_key_id;

        // A user name is not a secret.
        if ($this->isSftp()) {
            return $key;
        }

        return strlen($key) <= 8 ? str_repeat('•', strlen($key)) : substr($key, 0, 4).'…'.substr($key, -4);
    }

    /**
     * Null until the first check, then whether the last one reached the account.
     */
    public function reachable(): ?bool
    {
        if ($this->checked_at === null) {
            return null;
        }

        return $this->check_error === null;
    }

    /**
     * The connection as the API returns it. The secret never leaves the server, and the access
     * key ID is masked.
     *
     * @return array<string, mixed>
     */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'provider' => $this->provider,
            'provider_label' => $this->providerLabel(),
            'endpoint' => $this->endpoint,
            'region' => $this->region,
            'path_style' => (bool) $this->path_style,
            'access_key_id' => $this->maskedKey(),
            'host_key_pinned' => $this->isSftp() ? $this->host_key !== null : null,
            'reachable' => $this->reachable(),
            'check_error' => $this->check_error,
            'checked_at' => $this->checked_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
