<?php

namespace App\Services;

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
     * @return array{folders: list<string>, files: list<array{key: string, size: int, last_modified: ?string}>}
     */
    public function listObjects(string $bucket, string $prefix = ''): array
    {
        $res = $this->client()->listObjectsV2([
            'Bucket' => $bucket,
            'Prefix' => $prefix,
            'Delimiter' => '/',
        ]);

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

        return ['folders' => $folders, 'files' => $files];
    }

    public function getBucketSize(string $bucket): int
    {
        $total = 0;
        $token = null;

        do {
            $params = ['Bucket' => $bucket, 'Delimiter' => ''];
            if ($token) {
                $params['ContinuationToken'] = $token;
            }

            $res = $this->client()->listObjectsV2($params);

            foreach ($res['Contents'] ?? [] as $obj) {
                $total += (int) ($obj['Size'] ?? 0);
            }

            $token = $res['IsTruncated'] ? ($res['NextContinuationToken'] ?? null) : null;
        } while ($token);

        return $total;
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

    public function getObjectStream(string $bucket, string $key)
    {
        $res = $this->client()->getObject([
            'Bucket' => $bucket,
            'Key' => $key,
        ]);

        return $res['Body'];
    }

    public function deleteObject(string $bucket, string $key): void
    {
        $this->client()->deleteObject([
            'Bucket' => $bucket,
            'Key' => $key,
        ]);
    }
}
