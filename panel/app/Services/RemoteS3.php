<?php

namespace App\Services;

use App\Models\RemoteConnection;
use Aws\Exception\AwsException;
use Aws\S3\S3Client;

/**
 * Talks S3 to another provider with the credentials of a connection: enough to check them and
 * to list the buckets on offer. The transfers themselves are run by rclone.
 */
class RemoteS3
{
    public function client(RemoteConnection $connection): S3Client
    {
        $config = [
            'version' => '2006-03-01',
            'region' => $connection->signingRegion(),
            'credentials' => [
                'key' => (string) $connection->access_key_id,
                'secret' => (string) $connection->secret_access_key,
            ],
            'use_path_style_endpoint' => (bool) $connection->path_style,
            'http' => ['connect_timeout' => 6, 'timeout' => 25],
        ];

        if ($connection->endpoint) {
            $config['endpoint'] = $connection->endpoint;
        }

        return new S3Client($config);
    }

    /**
     * @return list<array{name: string, created: ?string}>
     */
    public function listBuckets(RemoteConnection $connection): array
    {
        // An SFTP server has folders where an S3 account has buckets.
        if ($connection->isSftp()) {
            return app(RemoteSftp::class)->listFolders($connection);
        }

        $out = [];
        foreach ($this->client($connection)->listBuckets()['Buckets'] ?? [] as $bucket) {
            $out[] = [
                'name' => (string) $bucket['Name'],
                'created' => isset($bucket['CreationDate']) ? $bucket['CreationDate']->format('c') : null,
            ];
        }

        usort($out, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return $out;
    }

    /**
     * Try the credentials and record the outcome on the connection. Returns the error, or null.
     * A key that may not list buckets (AccessDenied) still proves the account answers: that is
     * recorded as reachable, and bucket names are typed by hand.
     */
    public function check(RemoteConnection $connection): ?string
    {
        if ($connection->isSftp()) {
            return app(RemoteSftp::class)->check($connection);
        }

        $error = null;

        try {
            $this->client($connection)->listBuckets();
        } catch (\Throwable $e) {
            $error = $this->isAccessDenied($e) ? null : $this->describe($e);
        }

        $connection->forceFill(['checked_at' => now(), 'check_error' => $error])->save();

        return $error;
    }

    public function isAccessDenied(\Throwable $e): bool
    {
        return $e instanceof AwsException
            && ($e->getStatusCode() === 403 && in_array($e->getAwsErrorCode(), ['AccessDenied', 'AllAccessDisabled', 'Forbidden'], true));
    }

    /**
     * A short reason a person can act on, instead of the SDK's multi-line error.
     */
    public function describe(\Throwable $e): string
    {
        if (! $e instanceof AwsException && $e instanceof \RuntimeException) {
            return app(RemoteSftp::class)->describe($e);
        }

        if ($e instanceof AwsException) {
            $message = $e->getAwsErrorMessage() ?: ($e->getAwsErrorCode() ?: '');

            if ($message !== '') {
                return match ($e->getAwsErrorCode()) {
                    'InvalidAccessKeyId' => 'The access key ID is not known to this provider.',
                    'SignatureDoesNotMatch' => 'The secret access key does not match (or the region is wrong).',
                    default => $message,
                };
            }

            if ($e->getStatusCode() === null) {
                return 'The endpoint did not answer. Check the URL.';
            }
        }

        return mb_strimwidth(trim(strtok($e->getMessage(), "\n") ?: $e->getMessage()), 0, 300, '…');
    }
}
