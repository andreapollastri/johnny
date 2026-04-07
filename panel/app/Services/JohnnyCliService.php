<?php

namespace App\Services;

use Illuminate\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

class JohnnyCliService
{
    public const SYSTEM_KEY_NAMES = ['johnny-default', 'johnny-backup'];

    /** Internal key used by nightly SFTP backup (`backup-internal-s3.env`). */
    public const BACKUP_KEY_NAME = 'johnny-backup';

    public const PROTECTED_BUCKETS = ['default'];

    public function runJohnny(array $args): ?string
    {
        $r = $this->runJohnnyResult($args);

        return $r->successful() ? $r->output() : null;
    }

    public function runJohnnyResult(array $args): ProcessResult
    {
        return Process::run(array_merge(['sudo', '-u', 'johnny', '/usr/local/bin/johnny'], $args));
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    public function parseKeyList(string $output): array
    {
        $keys = [];
        foreach (explode("\n", $output) as $line) {
            $line = trim($line);
            if (preg_match('/^(GK[0-9a-f]+)\s+\d{4}-\d{2}-\d{2}\s+(\S+)/i', $line, $m)) {
                $keys[] = ['id' => trim($m[1]), 'name' => trim($m[2])];
            } elseif (preg_match('/^(GK[0-9a-f]+)\s+(\S+)/i', $line, $m)) {
                $keys[] = ['id' => trim($m[1]), 'name' => trim($m[2])];
            }
        }

        return $keys;
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    public function listAllKeys(): array
    {
        $out = $this->runJohnny(['key', 'list']);

        return $this->parseKeyList($out ?? '');
    }

    /**
     * @return array{access_key_id: string, secret_access_key: string}|null
     */
    public function parseKeyCreateOutput(string $output): ?array
    {
        $accessKeyId = '';
        $secret = '';

        foreach (explode("\n", $output) as $line) {
            if (preg_match('/^\s*Key ID:\s*(.+)$/i', $line, $m)) {
                $accessKeyId = trim($m[1]);
            }
            if (preg_match('/^\s*Secret key:\s*(.+)$/i', $line, $m)) {
                $secret = trim($m[1]);
            }
        }

        if ($accessKeyId === '' || $secret === '') {
            return null;
        }

        return ['access_key_id' => $accessKeyId, 'secret_access_key' => $secret];
    }

    /**
     * @return array{0: list<array{id: string, name: string, permissions: string}>, 1: string}
     */
    public function parseBucketInfo(string $bucket): array
    {
        $result = $this->runJohnnyResult([
            'bucket', 'info', $bucket,
        ]);

        $raw = $result->successful() ? $result->output() : '';
        $keys = [];

        foreach (explode("\n", $raw) as $line) {
            $trimmed = trim($line);
            if (preg_match('/^([RWO]+)\s+(GK[0-9a-f]+)\s*(.*)$/i', $trimmed, $m)) {
                $perms = str_split(strtoupper(trim($m[1])));
                $readable = [];
                if (in_array('R', $perms)) {
                    $readable[] = 'read';
                }
                if (in_array('W', $perms)) {
                    $readable[] = 'write';
                }
                if (in_array('O', $perms)) {
                    $readable[] = 'owner';
                }

                $keys[] = [
                    'id' => trim($m[2]),
                    'name' => trim($m[3]),
                    'permissions' => implode(', ', $readable),
                ];
            }
        }

        return [$keys, $raw];
    }

    public function resolveCanonicalKeyId(string $keyId): ?string
    {
        foreach ($this->listAllKeys() as $k) {
            if (strcasecmp($k['id'], $keyId) === 0) {
                return $k['id'];
            }
        }

        return null;
    }

    public function isSystemKey(string $keyId): bool
    {
        $panelKeyId = config('services.garage.key');
        if ($panelKeyId && strcasecmp($keyId, $panelKeyId) === 0) {
            return true;
        }

        foreach ($this->listAllKeys() as $k) {
            if (strcasecmp($k['id'], $keyId) === 0 && in_array($k['name'], self::SYSTEM_KEY_NAMES, true)) {
                return true;
            }
        }

        return false;
    }

    public function allowKeyOnBucket(string $keyName, string $bucket): void
    {
        Process::run([
            'sudo', '-u', 'johnny',
            '/usr/local/bin/johnny',
            'bucket', 'allow', '--read', '--write', '--owner', $bucket, '--key', $keyName,
        ]);
    }

    /**
     * Read-only access for the internal backup key (nightly rclone sync).
     */
    public function allowBackupKeyReadOnBucket(string $bucket): void
    {
        Process::run([
            'sudo', '-u', 'johnny',
            '/usr/local/bin/johnny',
            'bucket', 'allow', '--read', $bucket, '--key', self::BACKUP_KEY_NAME,
        ]);
    }

    /**
     * Grant the panel/app key (rw+owner) and the backup key (read) on a bucket.
     */
    public function ensureDefaultSystemKeysOnBucket(string $bucket): void
    {
        $this->allowKeyOnBucket(config('services.garage.key_name', 'johnny-default'), $bucket);
        $this->allowBackupKeyReadOnBucket($bucket);
    }

    /**
     * @return list<string>
     */
    public function listAllBucketNamesFromGarage(): array
    {
        $result = $this->runJohnnyResult(['bucket', 'list']);
        if (! $result->successful()) {
            return [];
        }

        return $this->parseGarageBucketList($result->output());
    }

    /**
     * Parse `johnny bucket list` / `garage bucket list` stdout.
     * Garage 2.x: ID (16 hex) TAB Created TAB Global aliases TAB Local aliases.
     * Older: global_aliases TAB local TAB long hex id; or UUID + name on one line.
     *
     * @return list<string>
     */
    public function parseGarageBucketList(string $output): array
    {
        $names = [];
        $hexId = '/^[0-9a-f]{32,128}$/';
        $hex16 = '/^[0-9a-f]{16}$/';
        $uuidLine = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\s+([a-z0-9][a-z0-9._-]*)$/i';
        $nameOk = '/^[a-z0-9][a-z0-9._-]*$/';

        foreach (explode("\n", $output) as $line) {
            $line = rtrim($line);
            if ($line === '' || stripos($line, 'list of buckets') !== false) {
                continue;
            }

            $low = strtolower($line);
            $stripped = trim(str_replace('|', '', $line));
            if (str_starts_with($stripped, 'ID') && str_contains($low, 'global') && str_contains($low, 'alias')) {
                continue;
            }
            if (str_starts_with($stripped, 'ID') && str_contains($low, 'created')) {
                continue;
            }

            $colsTab = array_map('trim', explode("\t", $line));
            if (count($colsTab) >= 3
                && preg_match($hex16, $colsTab[0])
                && preg_match('/^\d{4}-\d{2}-\d{2}/', $colsTab[1])) {
                foreach (array_map('trim', explode(',', $colsTab[2])) as $alias) {
                    if ($alias !== '' && preg_match($nameOk, $alias)) {
                        $names[] = $alias;
                    }
                }

                continue;
            }

            if (preg_match($uuidLine, $stripped, $m)) {
                $names[] = $m[1];

                continue;
            }

            $cols = str_contains($stripped, "\t")
                ? array_map('trim', explode("\t", $stripped))
                : preg_split('/\s{2,}|\s+/u', $stripped);
            $cols = array_values(array_filter($cols, fn ($c) => $c !== ''));
            if (count($cols) < 2) {
                continue;
            }

            $last = $cols[count($cols) - 1];
            if (preg_match($hexId, $last)) {
                foreach (array_map('trim', explode(',', $cols[0])) as $alias) {
                    if ($alias !== '' && preg_match($nameOk, $alias)) {
                        $names[] = $alias;
                    }
                }

                continue;
            }

            $first = $cols[0];
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $first)) {
                $tail = $cols[count($cols) - 1];
                if (preg_match($nameOk, $tail)) {
                    $names[] = $tail;
                }
            }
        }

        return array_values(array_unique($names));
    }

    public function bucketAllow(string $bucket, string $keyId, bool $read, bool $write, bool $owner): ProcessResult
    {
        $cmd = ['sudo', '-u', 'johnny', '/usr/local/bin/johnny', 'bucket', 'allow'];
        if ($read) {
            $cmd[] = '--read';
        }
        if ($write) {
            $cmd[] = '--write';
        }
        if ($owner) {
            $cmd[] = '--owner';
        }
        $cmd[] = $bucket;
        $cmd[] = '--key';
        $cmd[] = $keyId;

        return Process::run($cmd);
    }

    public function bucketDeny(string $bucket, string $keyId, bool $read, bool $write, bool $owner): ProcessResult
    {
        $cmd = ['sudo', '-u', 'johnny', '/usr/local/bin/johnny', 'bucket', 'deny'];
        if ($read) {
            $cmd[] = '--read';
        }
        if ($write) {
            $cmd[] = '--write';
        }
        if ($owner) {
            $cmd[] = '--owner';
        }
        $cmd[] = $bucket;
        $cmd[] = '--key';
        $cmd[] = $keyId;

        return Process::run($cmd);
    }
}
