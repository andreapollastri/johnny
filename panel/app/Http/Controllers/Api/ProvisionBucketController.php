<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\JohnnyCliService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;

class ProvisionBucketController extends Controller
{
    /** Garage key names are limited to 128 characters in the panel UI; stay within the same bound. */
    private const MAX_GARAGE_KEY_NAME_LEN = 128;

    public function __construct(
        private JohnnyCliService $johnny
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'bucket' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9][a-z0-9._-]{1,254}$/'],
        ]);

        $bucketName = $validated['bucket'] ?? $this->randomBucketName();

        $createBucket = Process::run([
            'sudo', '-u', 'johnny',
            '/usr/local/bin/johnny',
            'bucket', 'create', $bucketName,
        ]);

        if (! $createBucket->successful()) {
            $err = trim($createBucket->errorOutput() ?: $createBucket->output());

            return response()->json([
                'message' => 'Failed to create bucket.',
                'detail' => $err !== '' ? $err : 'Unknown error from johnny bucket create.',
            ], 422);
        }

        $parsed = null;
        $keyName = '';
        $keyText = '';

        for ($attempt = 0; $attempt < 8; $attempt++) {
            $keyName = $this->randomGarageKeyName($bucketName);
            $keyResult = Process::run([
                'sudo', '-u', 'johnny',
                '/usr/local/bin/johnny',
                'key', 'create', $keyName,
            ]);

            $keyText = trim($keyResult->output()."\n".$keyResult->errorOutput());
            $parsed = $this->johnny->parseKeyCreateOutput($keyText);

            if ($keyResult->successful() && $parsed !== null) {
                break;
            }
            $parsed = null;
        }

        if ($parsed === null) {
            return response()->json([
                'message' => 'Bucket was created but key creation failed.',
                'bucket' => $bucketName,
                'detail' => $keyText !== '' ? $keyText : 'Unknown error from johnny key create.',
            ], 500);
        }

        $allowNew = Process::run([
            'sudo', '-u', 'johnny',
            '/usr/local/bin/johnny',
            'bucket', 'allow', '--read', '--write', '--owner', $bucketName, '--key', $keyName,
        ]);

        if (! $allowNew->successful()) {
            return response()->json([
                'message' => 'Bucket and key were created but granting permissions to the new key failed.',
                'bucket' => $bucketName,
                'key_name' => $keyName,
                'credentials' => [
                    'access_key_id' => $parsed['access_key_id'],
                    'secret_access_key' => $parsed['secret_access_key'],
                ],
                'detail' => trim($allowNew->errorOutput() ?: $allowNew->output()),
            ], 500);
        }

        $panelKeyName = config('services.garage.key_name', 'johnny-default');
        Process::run([
            'sudo', '-u', 'johnny',
            '/usr/local/bin/johnny',
            'bucket', 'allow', '--read', '--write', '--owner', $bucketName, '--key', $panelKeyName,
        ]);

        $this->johnny->allowBackupKeyReadOnBucket($bucketName);

        $endpoint = config('services.garage.endpoint');
        $region = config('services.garage.region', 'johnny');

        return response()->json([
            'bucket' => $bucketName,
            'region' => $region,
            'endpoint' => $endpoint,
            'path_style' => true,
            'key_name' => $keyName,
            'credentials' => [
                'access_key_id' => $parsed['access_key_id'],
                'secret_access_key' => $parsed['secret_access_key'],
            ],
            'env' => [
                'AWS_ACCESS_KEY_ID' => $parsed['access_key_id'],
                'AWS_SECRET_ACCESS_KEY' => $parsed['secret_access_key'],
                'AWS_DEFAULT_REGION' => $region,
                'AWS_ENDPOINT_URL' => $endpoint,
            ],
        ], 201);
    }

    private function randomBucketName(): string
    {
        return 'b-'.bin2hex(random_bytes(8));
    }

    /**
     * Key name format: "{bucket}-{random hex}" (bucket prefix truncated if needed for Garage limits).
     */
    private function randomGarageKeyName(string $bucketName): string
    {
        $suffix = bin2hex(random_bytes(4));
        $sepLen = 1;
        $maxPrefixLen = self::MAX_GARAGE_KEY_NAME_LEN - $sepLen - strlen($suffix);
        $prefix = $bucketName;
        if (strlen($prefix) > $maxPrefixLen) {
            $prefix = substr($prefix, 0, $maxPrefixLen);
        }

        return $prefix.'-'.$suffix;
    }
}
