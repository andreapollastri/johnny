<?php

namespace App\Support;

/**
 * The S3 providers a connection can point at. `rclone` is the provider name rclone's S3 backend
 * expects; `endpoint` and `region` are the examples the form shows, `default_region` is what a
 * connection uses when its region is left empty.
 */
class StorageProviders
{
    public const FALLBACK = 'other';

    /**
     * @return array<string, array{label: string, rclone: string, needs_endpoint: bool, endpoint: string, region: string, default_region: string, path_style: bool, note: string}>
     */
    public static function all(): array
    {
        return [
            'aws' => [
                'label' => 'Amazon S3',
                'rclone' => 'AWS',
                'needs_endpoint' => false,
                'endpoint' => '',
                'region' => 'eu-central-1',
                'default_region' => 'us-east-1',
                'path_style' => false,
                'note' => 'Use an IAM access key. The region must be the one the bucket lives in; leave the endpoint empty.',
            ],
            'r2' => [
                'label' => 'Cloudflare R2',
                'rclone' => 'Cloudflare',
                'needs_endpoint' => true,
                'endpoint' => 'https://<account-id>.r2.cloudflarestorage.com',
                'region' => 'auto',
                'default_region' => 'auto',
                'path_style' => true,
                'note' => 'Create an R2 API token with S3 credentials. A token limited to one bucket cannot list buckets: type the bucket name by hand.',
            ],
            'spaces' => [
                'label' => 'DigitalOcean Spaces',
                'rclone' => 'DigitalOcean',
                'needs_endpoint' => true,
                'endpoint' => 'https://fra1.digitaloceanspaces.com',
                'region' => 'us-east-1',
                'default_region' => 'us-east-1',
                'path_style' => true,
                'note' => 'The endpoint carries the datacenter (fra1, ams3, nyc3…). The region stays us-east-1 whatever the datacenter.',
            ],
            'b2' => [
                'label' => 'Backblaze B2',
                'rclone' => 'Other',
                'needs_endpoint' => true,
                'endpoint' => 'https://s3.eu-central-003.backblazeb2.com',
                'region' => 'eu-central-003',
                'default_region' => 'us-west-004',
                'path_style' => true,
                'note' => 'Use an application key; the endpoint is shown on the bucket page as "S3 endpoint".',
            ],
            'wasabi' => [
                'label' => 'Wasabi',
                'rclone' => 'Wasabi',
                'needs_endpoint' => true,
                'endpoint' => 'https://s3.eu-central-1.wasabisys.com',
                'region' => 'eu-central-1',
                'default_region' => 'us-east-1',
                'path_style' => true,
                'note' => 'The endpoint and the region must both name the region of the bucket.',
            ],
            'hetzner' => [
                'label' => 'Hetzner Object Storage',
                'rclone' => 'Other',
                'needs_endpoint' => true,
                'endpoint' => 'https://fsn1.your-objectstorage.com',
                'region' => 'fsn1',
                'default_region' => 'fsn1',
                'path_style' => true,
                'note' => 'The endpoint carries the location (fsn1, nbg1, hel1); use the same value as region.',
            ],
            'scaleway' => [
                'label' => 'Scaleway Object Storage',
                'rclone' => 'Scaleway',
                'needs_endpoint' => true,
                'endpoint' => 'https://s3.fr-par.scw.cloud',
                'region' => 'fr-par',
                'default_region' => 'fr-par',
                'path_style' => true,
                'note' => 'The endpoint and the region must both name the region of the bucket (fr-par, nl-ams, pl-waw).',
            ],
            'minio' => [
                'label' => 'MinIO',
                'rclone' => 'Minio',
                'needs_endpoint' => true,
                'endpoint' => 'https://minio.example.com',
                'region' => 'us-east-1',
                'default_region' => 'us-east-1',
                'path_style' => true,
                'note' => 'The URL of the MinIO S3 API (not the console).',
            ],
            'johnny' => [
                'label' => 'Johnny / Garage',
                'rclone' => 'Other',
                'needs_endpoint' => true,
                'endpoint' => 'https://storage.example.com',
                'region' => 'johnny',
                'default_region' => 'johnny',
                'path_style' => true,
                'note' => 'Another Johnny server: its S3 URL and one of its access keys. A plain Garage cluster uses the region "garage" unless configured otherwise.',
            ],
            'other' => [
                'label' => 'Other S3-compatible',
                'rclone' => 'Other',
                'needs_endpoint' => true,
                'endpoint' => 'https://s3.example.com',
                'region' => 'us-east-1',
                'default_region' => 'us-east-1',
                'path_style' => true,
                'note' => 'Any service that speaks the S3 API. Turn path-style addressing off if the service needs bucket names in the hostname.',
            ],
            'sftp' => [
                'label' => 'SFTP server',
                'rclone' => 'sftp',
                'needs_endpoint' => false,
                'endpoint' => '',
                'region' => '',
                'default_region' => '',
                'path_style' => false,
                'note' => 'Any server you reach over SSH: a VPS, a NAS, a storage box. Buckets are synced with folders of the server.',
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function ids(): array
    {
        return array_keys(self::all());
    }

    /**
     * @return array{label: string, rclone: string, needs_endpoint: bool, endpoint: string, region: string, default_region: string, path_style: bool, note: string}
     */
    public static function get(string $id): array
    {
        $all = self::all();

        return $all[$id] ?? $all[self::FALLBACK];
    }

    /**
     * Connections speak S3, except the ones to a server reached over SSH.
     */
    public static function isSftp(string $id): bool
    {
        return $id === 'sftp';
    }

    public static function label(string $id): string
    {
        return self::get($id)['label'];
    }
}
