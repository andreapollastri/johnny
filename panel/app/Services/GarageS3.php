<?php

namespace App\Services;

use Aws\Exception\AwsException;
use Aws\Result;
use Aws\S3\S3Client;

class GarageS3
{
    /** Lifecycle rules written by the panel; rules with other ids are left alone. */
    public const EXPIRE_RULE = 'johnny-expire';

    public const ABORT_UPLOADS_RULE = 'johnny-abort-uploads';

    /** Keys per DeleteObjects request (the S3 maximum). */
    private const DELETE_BATCH = 1000;

    private ?S3Client $client = null;

    public function client(): S3Client
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $key = config('services.garage.key');
        $secret = config('services.garage.secret');
        $endpoint = config('services.garage.endpoint');
        $region = config('services.garage.region', 'johnny');

        if (! $key || ! $secret || ! $endpoint) {
            throw new \RuntimeException('Garage S3 is not configured (GARAGE_* in .env).');
        }

        $this->client = new S3Client([
            'version' => '2006-03-01',
            'region' => $region,
            'endpoint' => $endpoint,
            'credentials' => [
                'key' => $key,
                'secret' => $secret,
            ],
            'use_path_style_endpoint' => true,
        ]);

        return $this->client;
    }

    /**
     * @return list<array{name: string, created: ?string}>
     */
    public function listBuckets(): array
    {
        $res = $this->client()->listBuckets();
        $out = [];
        foreach ($res['Buckets'] ?? [] as $b) {
            $out[] = [
                'name' => $b['Name'],
                'created' => isset($b['CreationDate']) ? $b['CreationDate']->format('c') : null,
            ];
        }

        usort($out, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return $out;
    }

    public function createBucket(string $name): void
    {
        $this->client()->createBucket(['Bucket' => $name]);
    }

    public function deleteBucket(string $name): void
    {
        $this->client()->deleteBucket(['Bucket' => $name]);
    }

    /**
     * One page of a "directory" listing (folders are S3 common prefixes).
     *
     * @return array{folders: list<string>, files: list<array{key: string, size: int, last_modified: ?string}>, next_token: ?string}
     */
    public function listObjects(string $bucket, string $prefix = '', ?string $continuationToken = null, int $maxKeys = 1000): array
    {
        $params = [
            'Bucket' => $bucket,
            'Prefix' => $prefix,
            'Delimiter' => '/',
            'MaxKeys' => $maxKeys,
        ];
        if ($continuationToken) {
            $params['ContinuationToken'] = $continuationToken;
        }

        $res = $this->client()->listObjectsV2($params);

        $folders = [];
        foreach ($res['CommonPrefixes'] ?? [] as $cp) {
            $folders[] = $cp['Prefix'];
        }

        $files = [];
        foreach ($res['Contents'] ?? [] as $obj) {
            if ($obj['Key'] === $prefix) {
                continue;
            }
            $files[] = [
                'key' => $obj['Key'],
                'size' => (int) ($obj['Size'] ?? 0),
                'last_modified' => isset($obj['LastModified']) ? $obj['LastModified']->format('c') : null,
            ];
        }

        return [
            'folders' => $folders,
            'files' => $files,
            'next_token' => ! empty($res['IsTruncated']) ? ($res['NextContinuationToken'] ?? null) : null,
        ];
    }

    /**
     * @return array{size: int, count: int}
     */
    public function getBucketStats(string $bucket): array
    {
        $size = 0;
        $count = 0;
        $token = null;

        do {
            $params = ['Bucket' => $bucket, 'Delimiter' => ''];
            if ($token) {
                $params['ContinuationToken'] = $token;
            }

            $res = $this->client()->listObjectsV2($params);

            foreach ($res['Contents'] ?? [] as $obj) {
                $size += (int) ($obj['Size'] ?? 0);
                $count++;
            }

            $token = $res['IsTruncated'] ? ($res['NextContinuationToken'] ?? null) : null;
        } while ($token);

        return ['size' => $size, 'count' => $count];
    }

    /**
     * @param  resource|string  $body
     */
    public function putObject(string $bucket, string $key, $body, ?string $contentType = null): void
    {
        $params = [
            'Bucket' => $bucket,
            'Key' => $key,
            'Body' => $body,
        ];
        if ($contentType) {
            $params['ContentType'] = $contentType;
        }
        $this->client()->putObject($params);
    }

    /**
     * Zero-byte "folder/" marker so an empty folder shows up in listings.
     */
    public function createFolder(string $bucket, string $prefix): void
    {
        $this->putObject($bucket, rtrim($prefix, '/').'/', '');
    }

    /**
     * Upload a file from disk; large files go up in parts (multipart) without loading them in memory.
     */
    public function uploadFile(string $bucket, string $key, string $path, ?string $contentType = null): void
    {
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new \RuntimeException("Cannot read {$path}.");
        }

        try {
            $this->client()->upload($bucket, $key, $stream, 'private', [
                'params' => $contentType ? ['ContentType' => $contentType] : [],
            ]);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * GetObject result: Body (stream), ContentType, ContentLength, ContentRange, …
     * $range is an HTTP Range value ("bytes=0-1023") for a partial read.
     */
    public function getObject(string $bucket, string $key, ?string $range = null): Result
    {
        $params = ['Bucket' => $bucket, 'Key' => $key];
        if ($range !== null && $range !== '') {
            $params['Range'] = $range;
        }

        return $this->client()->getObject($params);
    }

    /**
     * @return array{key: string, size: int, content_type: ?string, etag: ?string, last_modified: ?string}
     */
    public function headObject(string $bucket, string $key): array
    {
        $res = $this->client()->headObject(['Bucket' => $bucket, 'Key' => $key]);

        return [
            'key' => $key,
            'size' => (int) ($res['ContentLength'] ?? 0),
            'content_type' => isset($res['ContentType']) ? (string) $res['ContentType'] : null,
            'etag' => isset($res['ETag']) ? trim((string) $res['ETag'], '"') : null,
            'last_modified' => isset($res['LastModified']) ? $res['LastModified']->format('c') : null,
        ];
    }

    public function objectExists(string $bucket, string $key): bool
    {
        try {
            $this->client()->headObject(['Bucket' => $bucket, 'Key' => $key]);
        } catch (AwsException $e) {
            if ($e->getStatusCode() === 404) {
                return false;
            }

            throw $e;
        }

        return true;
    }

    /**
     * Server-side copy inside one bucket (used with deleteObject to rename or move).
     */
    public function copyObject(string $bucket, string $from, string $to): void
    {
        $this->client()->copyObject([
            'Bucket' => $bucket,
            'Key' => $to,
            'CopySource' => S3Client::encodeKey($bucket.'/'.$from),
        ]);
    }

    public function deleteObject(string $bucket, string $key): void
    {
        $this->client()->deleteObject([
            'Bucket' => $bucket,
            'Key' => $key,
        ]);
    }

    /**
     * Delete many objects, 1000 per request. Returns how many were deleted.
     *
     * @param  list<string>  $keys
     */
    public function deleteObjects(string $bucket, array $keys): int
    {
        $deleted = 0;

        foreach (array_chunk(array_values(array_unique($keys)), self::DELETE_BATCH) as $batch) {
            $res = $this->client()->deleteObjects([
                'Bucket' => $bucket,
                'Delete' => [
                    'Objects' => array_map(fn (string $key) => ['Key' => $key], $batch),
                    'Quiet' => true,
                ],
            ]);

            $failed = $res['Errors'] ?? [];
            $deleted += count($batch) - count($failed);

            if ($failed !== []) {
                $first = $failed[0];

                throw new \RuntimeException(sprintf(
                    'Could not delete %s: %s',
                    $first['Key'] ?? 'an object',
                    $first['Message'] ?? $first['Code'] ?? 'unknown error',
                ));
            }
        }

        return $deleted;
    }

    /**
     * Delete everything under a "folder" (key prefix), up to $limit objects per call.
     * `truncated` is true when the folder held more: call again to continue.
     *
     * @return array{deleted: int, truncated: bool}
     */
    public function deletePrefix(string $bucket, string $prefix, int $limit = 5000): array
    {
        if ($prefix === '') {
            throw new \InvalidArgumentException('Refusing to delete a whole bucket by prefix.');
        }

        $deleted = 0;
        $token = null;

        do {
            $params = [
                'Bucket' => $bucket,
                'Prefix' => $prefix,
                'MaxKeys' => min(self::DELETE_BATCH, $limit - $deleted),
            ];
            if ($token) {
                $params['ContinuationToken'] = $token;
            }

            $res = $this->client()->listObjectsV2($params);

            $keys = array_column($res['Contents'] ?? [], 'Key');
            if ($keys !== []) {
                $deleted += $this->deleteObjects($bucket, $keys);
            }

            $token = ! empty($res['IsTruncated']) ? ($res['NextContinuationToken'] ?? null) : null;
        } while ($token && $deleted < $limit);

        return ['deleted' => $deleted, 'truncated' => $token !== null];
    }

    /**
     * A URL anyone can GET the object with until it expires (SigV4 allows at most 7 days).
     */
    public function presignedUrl(string $bucket, string $key, int $seconds): string
    {
        $command = $this->client()->getCommand('GetObject', ['Bucket' => $bucket, 'Key' => $key]);
        $seconds = max(60, min(604800, $seconds));

        return (string) $this->client()->createPresignedRequest($command, "+{$seconds} seconds")->getUri();
    }

    /**
     * The first CORS rule of the bucket, in the shape the settings form edits; `rules` counts
     * all of them (other tools may have written more than one).
     *
     * @return array{origins: list<string>, methods: list<string>, headers: list<string>, max_age: int, rules: int}
     */
    public function getCors(string $bucket): array
    {
        $rules = [];

        try {
            $rules = $this->client()->getBucketCors(['Bucket' => $bucket])['CORSRules'] ?? [];
        } catch (AwsException $e) {
            if (! $this->isMissingConfiguration($e)) {
                throw $e;
            }
        }

        $first = $rules[0] ?? [];

        return [
            'origins' => array_values((array) ($first['AllowedOrigins'] ?? [])),
            'methods' => array_values((array) ($first['AllowedMethods'] ?? [])),
            'headers' => array_values((array) ($first['AllowedHeaders'] ?? [])),
            'max_age' => (int) ($first['MaxAgeSeconds'] ?? 3600),
            'rules' => count($rules),
        ];
    }

    /**
     * Replace the bucket's CORS configuration with one rule; no origins removes it.
     *
     * @param  list<string>  $origins
     * @param  list<string>  $methods
     * @param  list<string>  $headers
     */
    public function putCors(string $bucket, array $origins, array $methods, array $headers = ['*'], int $maxAge = 3600): void
    {
        if ($origins === [] || $methods === []) {
            $this->client()->deleteBucketCors(['Bucket' => $bucket]);

            return;
        }

        $this->client()->putBucketCors([
            'Bucket' => $bucket,
            'CORSConfiguration' => [
                'CORSRules' => [[
                    'AllowedOrigins' => array_values($origins),
                    'AllowedMethods' => array_values($methods),
                    'AllowedHeaders' => array_values($headers ?: ['*']),
                    'ExposeHeaders' => ['ETag'],
                    'MaxAgeSeconds' => $maxAge,
                ]],
            ],
        ]);
    }

    /**
     * The two lifecycle rules the panel manages; `other_rules` counts rules written by other tools.
     *
     * @return array{expire_days: ?int, expire_prefix: string, abort_days: ?int, other_rules: int}
     */
    public function getLifecycle(string $bucket): array
    {
        $out = ['expire_days' => null, 'expire_prefix' => '', 'abort_days' => null, 'other_rules' => 0];

        foreach ($this->lifecycleRules($bucket) as $rule) {
            $enabled = ($rule['Status'] ?? '') === 'Enabled';

            if (($rule['ID'] ?? '') === self::EXPIRE_RULE) {
                $out['expire_days'] = $enabled && isset($rule['Expiration']['Days']) ? (int) $rule['Expiration']['Days'] : null;
                $out['expire_prefix'] = (string) ($rule['Filter']['Prefix'] ?? $rule['Prefix'] ?? '');
            } elseif (($rule['ID'] ?? '') === self::ABORT_UPLOADS_RULE) {
                $days = $rule['AbortIncompleteMultipartUpload']['DaysAfterInitiation'] ?? null;
                $out['abort_days'] = $enabled && $days !== null ? (int) $days : null;
            } else {
                $out['other_rules']++;
            }
        }

        return $out;
    }

    /**
     * Write the panel's lifecycle rules (null removes one), keeping rules written by other tools.
     */
    public function putLifecycle(string $bucket, ?int $expireDays, string $expirePrefix, ?int $abortDays): void
    {
        $rules = array_values(array_filter(
            $this->lifecycleRules($bucket),
            fn (array $rule) => ! in_array($rule['ID'] ?? '', [self::EXPIRE_RULE, self::ABORT_UPLOADS_RULE], true),
        ));

        if ($expireDays !== null) {
            $rules[] = [
                'ID' => self::EXPIRE_RULE,
                'Status' => 'Enabled',
                'Filter' => ['Prefix' => $expirePrefix],
                'Expiration' => ['Days' => $expireDays],
            ];
        }

        if ($abortDays !== null) {
            $rules[] = [
                'ID' => self::ABORT_UPLOADS_RULE,
                'Status' => 'Enabled',
                'Filter' => ['Prefix' => ''],
                'AbortIncompleteMultipartUpload' => ['DaysAfterInitiation' => $abortDays],
            ];
        }

        if ($rules === []) {
            $this->client()->deleteBucketLifecycle(['Bucket' => $bucket]);

            return;
        }

        $this->client()->putBucketLifecycleConfiguration([
            'Bucket' => $bucket,
            'LifecycleConfiguration' => ['Rules' => $rules],
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lifecycleRules(string $bucket): array
    {
        try {
            return array_values((array) ($this->client()->getBucketLifecycleConfiguration(['Bucket' => $bucket])['Rules'] ?? []));
        } catch (AwsException $e) {
            if (! $this->isMissingConfiguration($e)) {
                throw $e;
            }
        }

        return [];
    }

    /**
     * S3 answers 404 NoSuch…Configuration when a bucket has no CORS or lifecycle rules (Garage: 204).
     */
    private function isMissingConfiguration(AwsException $e): bool
    {
        $code = (string) $e->getAwsErrorCode();

        return in_array($code, ['NoSuchCORSConfiguration', 'NoSuchLifecycleConfiguration'], true)
            || ($e->getStatusCode() === 404 && $code !== 'NoSuchBucket');
    }
}
