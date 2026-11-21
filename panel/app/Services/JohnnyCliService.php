<?php

namespace App\Services;

use App\Models\GlobalKey;
use App\Support\Format;
use App\Support\KeyScopes;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

class JohnnyCliService
{
    public const SYSTEM_KEY_NAMES = ['johnny-default', 'johnny-backup'];

    /** Internal key used by nightly SFTP backup (`backup-internal-s3.env`). */
    public const BACKUP_KEY_NAME = 'johnny-backup';

    public const PROTECTED_BUCKETS = ['default'];

    /**
     * Full argv for a johnny CLI call: `sudo -n -u johnny /usr/local/bin/johnny …` by default.
     *
     * @param  list<string>  $args
     * @return list<string>
     */
    public function command(array $args): array
    {
        $binary = (string) config('services.johnny.binary', '/usr/local/bin/johnny');
        $sudoUser = (string) config('services.johnny.sudo_user', 'johnny');

        $prefix = $sudoUser !== '' ? ['sudo', '-n', '-u', $sudoUser] : [];

        return [...$prefix, $binary, ...$args];
    }

    public function runJohnny(array $args): ?string
    {
        $r = $this->runJohnnyResult($args);

        return $r->successful() ? $r->output() : null;
    }

    public function runJohnnyResult(array $args): ProcessResult
    {
        return Process::run($this->command($args));
    }

    /**
     * Human-readable failure detail from a CLI result (stderr first, then stdout).
     */
    public function errorDetail(ProcessResult $result, string $fallback): string
    {
        $detail = trim($result->errorOutput() ?: $result->output());

        return $detail !== '' ? $detail : $fallback;
    }

    public function createBucket(string $bucket): ProcessResult
    {
        return $this->runJohnnyResult(['bucket', 'create', $bucket]);
    }

    public function deleteBucket(string $bucket): ProcessResult
    {
        return $this->runJohnnyResult(['bucket', 'delete', '--yes', $bucket]);
    }

    public function createKey(string $name): ProcessResult
    {
        return $this->runJohnnyResult(['key', 'create', $name]);
    }

    /**
     * Callers must pass a canonical key id (see resolveCanonicalKeyId): Garage also accepts
     * id prefixes, which would otherwise slip past the system-key guard.
     */
    public function deleteKey(string $keyId): ProcessResult
    {
        $result = $this->runJohnnyResult(['key', 'delete', '--yes', $keyId]);

        if ($result->successful()) {
            try {
                // A deleted key no longer reaches all buckets.
                GlobalKey::query()->where('key_id', $keyId)->delete();
            } catch (\Throwable) {
                // The table is not there yet: the panel is being installed or migrated.
            }
        }

        return $result;
    }

    /**
     * Parse `garage key list`: "ID  Created  Name  Expiration" (2.x) or "ID  Name" (older).
     *
     * @return list<array{id: string, name: string, created: ?string}>
     */
    public function parseKeyList(string $output): array
    {
        $keys = [];
        foreach (explode("\n", $output) as $line) {
            $line = trim($line);
            if (preg_match('/^(GK[0-9a-f]+)\s+(\d{4}-\d{2}-\d{2})\s+(\S+)/i', $line, $m)) {
                $keys[] = ['id' => $m[1], 'name' => $m[3], 'created' => $m[2]];
            } elseif (preg_match('/^(GK[0-9a-f]+)\s+(\S+)/i', $line, $m)) {
                $keys[] = ['id' => $m[1], 'name' => $m[2], 'created' => null];
            }
        }

        return $keys;
    }

    /**
     * @return list<array{id: string, name: string, created: ?string}>
     */
    public function listAllKeys(): array
    {
        $out = $this->runJohnny(['key', 'list']);

        return $this->parseKeyList($out ?? '');
    }

    /**
     * Keys created by users (system keys hidden), sorted by name.
     *
     * @return list<array{id: string, name: string, created: ?string}>
     */
    public function userKeys(array $keys): array
    {
        $keys = array_values(array_filter(
            $keys,
            fn ($k) => ! in_array($k['name'], self::SYSTEM_KEY_NAMES, true),
        ));
        usort($keys, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']) ?: strcmp($a['id'], $b['id']));

        return $keys;
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
        $result = $this->runJohnnyResult(['bucket', 'info', $bucket]);
        $raw = $result->successful() ? $result->output() : '';

        return [$this->parseBucketInfoKeys($raw), $raw];
    }

    /**
     * Rows of the "KEYS FOR THIS BUCKET" table: "RWO  GK…  key-name".
     *
     * @return list<array{id: string, name: string, permissions: string}>
     */
    public function parseBucketInfoKeys(string $raw): array
    {
        $keys = [];

        foreach (explode("\n", $raw) as $line) {
            if (! preg_match('/^([RWO]+)\s+(GK[0-9a-f]+)\s*(.*)$/i', trim($line), $m)) {
                continue;
            }

            $perms = str_split(strtoupper($m[1]));
            $readable = array_values(array_filter([
                in_array('R', $perms, true) ? 'read' : null,
                in_array('W', $perms, true) ? 'write' : null,
                in_array('O', $perms, true) ? 'owner' : null,
            ]));

            $keys[] = [
                'id' => $m[2],
                'name' => trim($m[3]),
                'permissions' => implode(', ', $readable),
            ];
        }

        return $keys;
    }

    /**
     * Size, object count and quotas of a bucket, straight from Garage's counters (no listing).
     * `known` is false when `johnny bucket info` could not be run.
     *
     * @return array{known: bool, size: ?int, objects: ?int, max_size: ?int, max_objects: ?int}
     */
    public function bucketUsage(string $bucket): array
    {
        $result = $this->runJohnnyResult(['bucket', 'info', $bucket]);

        return ['known' => $result->successful(), ...$this->parseBucketUsage($result->successful() ? $result->output() : '')];
    }

    /**
     * Parse the counters of `garage bucket info`: "Size: 510.9 MiB (535.8 MB)", "Objects: 35" and,
     * when quotas are set, "maximum size: 10.0 GiB (10.7 GB)" / "maximum number of objects: 1000".
     * Sizes are what Garage prints, rounded to one decimal.
     *
     * @return array{size: ?int, objects: ?int, max_size: ?int, max_objects: ?int}
     */
    public function parseBucketUsage(string $raw): array
    {
        $number = fn (string $value) => (int) preg_replace('/\D/', '', $value);

        return [
            'size' => preg_match('/^\s*Size:\s*([^(\n]+)/mi', $raw, $m) ? Format::parseBytes(trim($m[1])) : null,
            'objects' => preg_match('/^\s*Objects:\s*([\d,.]+)/mi', $raw, $m) ? $number($m[1]) : null,
            'max_size' => preg_match('/maximum size:\s*([^(\n]+)/i', $raw, $m) ? Format::parseBytes(trim($m[1])) : null,
            'max_objects' => preg_match('/maximum number of objects:\s*([\d,.]+)/i', $raw, $m) ? $number($m[1]) : null,
        ];
    }

    /**
     * Set both quotas of a bucket: bytes and number of objects; null removes that limit.
     * Garage refuses uploads that would go over a quota.
     */
    public function setBucketQuotas(string $bucket, ?int $maxSize, ?int $maxObjects): ProcessResult
    {
        return $this->runJohnnyResult([
            'bucket', 'set-quotas',
            '--max-size', $maxSize === null ? 'none' : (string) $maxSize,
            '--max-objects', $maxObjects === null ? 'none' : (string) $maxObjects,
            $bucket,
        ]);
    }

    /**
     * Create the bucket when it does not exist yet, and make sure the system keys can use it.
     * Returns an error message, or null when the bucket is ready.
     */
    public function ensureBucket(string $bucket): ?string
    {
        if (! in_array($bucket, $this->listAllBucketNamesFromGarage(), true)) {
            $result = $this->createBucket($bucket);

            if (! $result->successful()) {
                return $this->errorDetail($result, "Bucket \"{$bucket}\" could not be created.");
            }
        }

        $this->ensureDefaultSystemKeysOnBucket($bucket);

        return null;
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

    public function allowKeyOnBucket(string $keyName, string $bucket): ProcessResult
    {
        return $this->bucketAllow($bucket, $keyName, true, true, true);
    }

    /**
     * Read-only access for the internal backup key (nightly rclone sync).
     */
    public function allowBackupKeyReadOnBucket(string $bucket): ProcessResult
    {
        return $this->bucketAllow($bucket, self::BACKUP_KEY_NAME, true, false, false);
    }

    /**
     * Grant the panel/app key (rw+owner) and the backup key (read) on a bucket, then the keys
     * that reach every bucket.
     */
    public function ensureDefaultSystemKeysOnBucket(string $bucket): void
    {
        $this->allowKeyOnBucket(config('services.garage.key_name', 'johnny-default'), $bucket);
        $this->allowBackupKeyReadOnBucket($bucket);
        $this->grantGlobalKeys($bucket);
    }

    /**
     * Grant the keys that reach every bucket (see GlobalKey) on one bucket, usually a new one.
     */
    public function grantGlobalKeys(string $bucket): void
    {
        try {
            $keys = GlobalKey::query()->get();
        } catch (\Throwable) {
            // The table is not there yet: the panel is being installed or migrated.
            return;
        }

        foreach ($keys as $key) {
            $this->bucketAllow($bucket, $key->key_id, ...KeyScopes::flags($key->scope));
        }
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
        $hexIdPrefix = '/^[0-9a-f]{8,64}$/i';
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
                && preg_match($hexIdPrefix, $colsTab[0])
                && preg_match('/^\d{4}-\d{2}-\d{2}/', $colsTab[1])) {
                foreach (array_map('trim', explode(',', $colsTab[2])) as $alias) {
                    if ($alias !== '' && preg_match($nameOk, $alias)) {
                        $names[] = $alias;
                    }
                }

                continue;
            }

            if (preg_match('/^([0-9a-f]{8,64})\s+(\d{4}-\d{2}-\d{2})\s+(.+)$/i', $stripped, $m)) {
                $rest = trim($m[3]);
                if (preg_match('/\s{2,}/', $rest)) {
                    $globalCell = preg_split('/\s{2,}/', $rest, 2)[0];
                } else {
                    $globalCell = $rest;
                }
                foreach (array_map('trim', explode(',', trim($globalCell))) as $alias) {
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
        return $this->runJohnnyResult($this->permissionArgs('allow', $bucket, $keyId, $read, $write, $owner));
    }

    public function bucketDeny(string $bucket, string $keyId, bool $read, bool $write, bool $owner): ProcessResult
    {
        return $this->runJohnnyResult($this->permissionArgs('deny', $bucket, $keyId, $read, $write, $owner));
    }

    /**
     * Give a key exactly the permissions of a scope on a bucket: grants what the scope includes
     * and revokes the rest. Callers must pass a canonical key id (see resolveCanonicalKeyId).
     */
    public function setKeyScope(string $bucket, string $keyId, string $scope): ProcessResult
    {
        [$read, $write, $owner] = KeyScopes::flags($scope);

        $result = $this->bucketAllow($bucket, $keyId, $read, $write, $owner);

        if ($result->successful() && ! ($read && $write && $owner)) {
            $result = $this->bucketDeny($bucket, $keyId, ! $read, ! $write, ! $owner);
        }

        return $result;
    }

    /**
     * The buckets a key can use, from `garage key info`. `known` is false when it could not run.
     *
     * @return array{known: bool, buckets: list<array{bucket: string, permissions: string}>, raw: string}
     */
    public function keyInfo(string $keyId): array
    {
        $result = $this->runJohnnyResult(['key', 'info', $keyId]);

        if (! $result->successful()) {
            return ['known' => false, 'buckets' => [], 'raw' => ''];
        }

        return [
            'known' => true,
            'buckets' => $this->parseKeyInfoBuckets($result->output()),
            'raw' => $result->output(),
        ];
    }

    /**
     * Rows of the "BUCKETS FOR THIS KEY" table: "RW   f1b17fa555341ee2  media". The three letters
     * keep their column, so a write-only row starts with a space (" W").
     *
     * @return list<array{bucket: string, permissions: string}>
     */
    public function parseKeyInfoBuckets(string $raw): array
    {
        $rows = [];
        $inTable = false;

        foreach (explode("\n", $raw) as $line) {
            if (str_contains($line, 'BUCKETS FOR THIS KEY')) {
                $inTable = true;

                continue;
            }

            if (! $inTable || ! preg_match('/^(.*?)\s+([0-9a-f]{16,64})\s+(\S+)/i', ' '.$line, $m)) {
                continue;
            }

            $flags = strtoupper((string) preg_replace('/\s+/', '', $m[1]));

            if ($flags === '' || ! preg_match('/^[RWO]+$/', $flags)) {
                continue;
            }

            $rows[] = [
                'bucket' => explode(',', $m[3])[0],
                'permissions' => implode(', ', array_values(array_filter([
                    str_contains($flags, 'R') ? 'read' : null,
                    str_contains($flags, 'W') ? 'write' : null,
                    str_contains($flags, 'O') ? 'owner' : null,
                ]))),
            ];
        }

        usort($rows, fn ($a, $b) => strnatcasecmp($a['bucket'], $b['bucket']));

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function permissionArgs(string $verb, string $bucket, string $keyId, bool $read, bool $write, bool $owner): array
    {
        return [
            'bucket', $verb,
            ...($read ? ['--read'] : []),
            ...($write ? ['--write'] : []),
            ...($owner ? ['--owner'] : []),
            $bucket,
            '--key', $keyId,
        ];
    }
}
