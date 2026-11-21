<?php

namespace App\Services\Transfers;

use App\Models\RemoteConnection;
use App\Models\Transfer;
use App\Support\StorageProviders;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * The rclone command line of a transfer. Both ends are remotes defined through environment
 * variables (RCLONE_CONFIG_SRC_*, RCLONE_CONFIG_DST_*): Johnny is always S3, the other side is
 * S3 or an SFTP server. No secret is written to disk or shown in the process list, and rclone
 * runs without a config file.
 */
class RcloneCommand
{
    /**
     * @return array{argv: list<string>, env: array<string, string>}
     */
    public static function forTransfer(Transfer $transfer, RemoteConnection $connection): array
    {
        $provider = StorageProviders::get($connection->provider);

        return self::build(
            binary: (string) config('services.johnny.transfers.rclone', 'rclone'),
            direction: $transfer->direction,
            mode: $transfer->mode,
            local: [
                'endpoint' => (string) (config('services.garage.internal_endpoint') ?: config('services.garage.endpoint')),
                'region' => (string) config('services.garage.region', 'johnny'),
                'key' => (string) config('services.garage.key'),
                'secret' => (string) config('services.garage.secret'),
            ],
            localBucket: $transfer->local_bucket,
            remote: $connection->isSftp() ? ['sftp' => self::sftpSettings($connection)] : [
                'provider' => $provider['rclone'],
                'endpoint' => (string) $connection->endpoint,
                'region' => $connection->signingRegion(),
                'key' => (string) $connection->access_key_id,
                'secret' => (string) $connection->secret_access_key,
                'path_style' => (bool) $connection->path_style,
            ],
            remoteBucket: $transfer->remote_bucket,
            prefix: (string) $transfer->prefix,
            dryRun: (bool) $transfer->dry_run,
            createBucket: (bool) $transfer->create_bucket,
        );
    }

    /**
     * What rclone needs to reach an SFTP server: `HOST`, `PORT`, `USER`, then `PASS` (obscured by
     * rclone itself, which is the only form it accepts) or `KEY_PEM`, and the pinned host keys.
     *
     * @return array<string, string>
     */
    public static function sftpSettings(RemoteConnection $connection): array
    {
        $settings = [
            'TYPE' => 'sftp',
            'HOST' => $connection->sftpHost(),
            'PORT' => (string) $connection->sftpPort(),
            'USER' => (string) $connection->access_key_id,
        ];

        $secret = (string) $connection->secret_access_key;

        if ($connection->usesPrivateKey()) {
            // rclone wants the PEM on one line, with its line breaks written as \n.
            $settings['KEY_PEM'] = str_replace(["\r\n", "\n"], '\n', trim($secret));
        } elseif ($secret !== '') {
            $settings['PASS'] = self::obscure($secret);
        }

        if ($connection->host_key !== null) {
            $path = storage_path('app/private/ssh/known_hosts-'.$connection->getKey());
            File::ensureDirectoryExists(dirname($path), 0700);
            File::put($path, $connection->host_key."\n");
            $settings['KNOWN_HOSTS_FILE'] = $path;
        }

        return $settings;
    }

    /**
     * The environment that defines one rclone remote (`SRC` or `DST`) as this SFTP connection.
     *
     * @return array<string, string>
     */
    public static function sftpEnvironment(string $remote, RemoteConnection $connection): array
    {
        $env = [];

        foreach (self::sftpSettings($connection) as $key => $value) {
            $env['RCLONE_CONFIG_'.$remote.'_'.$key] = $value;
        }

        return $env;
    }

    /**
     * `rclone obscure -` reads the password from its input, so it never shows in a process list.
     */
    private static function obscure(string $password): string
    {
        $result = Process::timeout(15)->input($password)->run([
            (string) config('services.johnny.transfers.rclone', 'rclone'), 'obscure', '-',
        ]);

        if (! $result->successful() || trim($result->output()) === '') {
            throw new \RuntimeException('rclone could not prepare the SFTP password: '.(trim($result->errorOutput()) ?: 'is it installed?'));
        }

        return trim($result->output());
    }

    /**
     * @param  array{endpoint: string, region: string, key: string, secret: string}  $local
     * @param  array<string, mixed>  $remote  An S3 account (provider, endpoint, region, key, secret, path_style), or `['sftp' => settings]` from sftpSettings().
     * @return array{argv: list<string>, env: array<string, string>}
     */
    public static function build(
        string $binary,
        string $direction,
        string $mode,
        array $local,
        string $localBucket,
        array $remote,
        string $remoteBucket,
        string $prefix = '',
        bool $dryRun = false,
        bool $createBucket = false,
    ): array {
        $import = $direction === Transfer::IMPORT;
        $path = fn (string $bucket) => $prefix === '' ? $bucket : $bucket.'/'.trim($prefix, '/');

        // Johnny's side: the bucket exists (the panel creates it before an import), never ask rclone to.
        $johnny = [
            'TYPE' => 's3',
            'PROVIDER' => 'Other',
            'ENV_AUTH' => 'false',
            'ACCESS_KEY_ID' => $local['key'],
            'SECRET_ACCESS_KEY' => $local['secret'],
            'ENDPOINT' => $local['endpoint'],
            'REGION' => $local['region'],
            'FORCE_PATH_STYLE' => 'true',
            'NO_CHECK_BUCKET' => 'true',
        ];

        if (isset($remote['sftp'])) {
            // A folder of a server: rclone creates it when missing, there is nothing to ask for.
            $other = $remote['sftp'];
        } else {
            $other = [
                'TYPE' => 's3',
                'PROVIDER' => $remote['provider'],
                'ENV_AUTH' => 'false',
                'ACCESS_KEY_ID' => $remote['key'],
                'SECRET_ACCESS_KEY' => $remote['secret'],
                'REGION' => $remote['region'],
                // A key limited to one bucket may not be allowed to check or create it: only try when asked.
                'NO_CHECK_BUCKET' => ! $import && $createBucket ? 'false' : 'true',
            ];
            if ($remote['endpoint'] !== '') {
                $other['ENDPOINT'] = $remote['endpoint'];
            }
            // rclone picks the addressing style AWS needs on its own.
            if ($remote['provider'] !== 'AWS') {
                $other['FORCE_PATH_STYLE'] = $remote['path_style'] ? 'true' : 'false';
            }
        }

        $env = [];
        foreach ($import ? $other : $johnny as $key => $value) {
            $env['RCLONE_CONFIG_SRC_'.$key] = $value;
        }
        foreach ($import ? $johnny : $other as $key => $value) {
            $env['RCLONE_CONFIG_DST_'.$key] = $value;
        }

        $argv = [
            $binary,
            $mode === Transfer::SYNC ? 'sync' : 'copy',
            'src:'.$path($import ? $remoteBucket : $localBucket),
            'dst:'.$path($import ? $localBucket : $remoteBucket),
            // An empty --config keeps rclone's configuration in memory: no file is read or written.
            '--config', '',
            '--use-json-log',
            '--log-level', 'INFO',
            '--stats', '2s',
            '--stats-log-level', 'NOTICE',
            // Compare size and checksum: reading modification times from S3 costs a request per object.
            // With an SFTP server that cannot hash files (no shell), rclone falls back to sizes.
            '--checksum',
            '--transfers', '8',
            '--checkers', '16',
            '--retries', '3',
            '--low-level-retries', '10',
        ];

        if ($dryRun) {
            $argv[] = '--dry-run';
        }

        return ['argv' => $argv, 'env' => $env];
    }
}
