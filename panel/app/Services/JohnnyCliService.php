<?php

namespace App\Services;

use Illuminate\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

class JohnnyCliService
{
    public const SYSTEM_KEY_NAMES = ['johnny-default', 'johnny-backup'];

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
