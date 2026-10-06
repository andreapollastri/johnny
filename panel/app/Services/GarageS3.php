<?php

namespace App\Services;

use Aws\Result;
use Aws\S3\S3Client;

class GarageS3
{
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
     * GetObject result: Body (stream), ContentType, ContentLength, …
     */
    public function getObject(string $bucket, string $key): Result
    {
        return $this->client()->getObject([
            'Bucket' => $bucket,
            'Key' => $key,
        ]);
    }

    public function deleteObject(string $bucket, string $key): void
    {
        $this->client()->deleteObject([
            'Bucket' => $bucket,
            'Key' => $key,
        ]);
    }
}
