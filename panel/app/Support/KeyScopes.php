<?php

namespace App\Support;

/**
 * What an access key may do in a bucket, as presets over Garage's three permissions.
 *
 * Garage enforces read, write and owner, nothing finer: a key that can write can also overwrite
 * and delete objects. The presets only name the combinations that are useful in practice.
 */
final class KeyScopes
{
    public const READ = 'read';

    public const READ_WRITE = 'read-write';

    public const WRITE = 'write';

    public const FULL = 'full';

    /**
     * @return array<string, array{label: string, summary: string, read: bool, write: bool, owner: bool}>
     */
    public static function all(): array
    {
        return [
            self::READ => [
                'label' => 'Read only',
                'summary' => 'List and download objects.',
                'read' => true,
                'write' => false,
                'owner' => false,
            ],
            self::READ_WRITE => [
                'label' => 'Read and write',
                'summary' => 'List, download, upload, overwrite and delete objects.',
                'read' => true,
                'write' => true,
                'owner' => false,
            ],
            self::WRITE => [
                'label' => 'Write only',
                'summary' => 'Upload, overwrite and delete objects, without reading them back.',
                'read' => false,
                'write' => true,
                'owner' => false,
            ],
            self::FULL => [
                'label' => 'Full control',
                'summary' => 'Everything above, plus the bucket settings (CORS, expiry rules) and deleting the bucket.',
                'read' => true,
                'write' => true,
                'owner' => true,
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

    public static function has(string $scope): bool
    {
        return isset(self::all()[$scope]);
    }

    public static function label(string $scope): string
    {
        return self::all()[$scope]['label'] ?? $scope;
    }

    /**
     * The read, write and owner flags of a scope, in the order the Garage CLI helpers take them.
     *
     * @return array{0: bool, 1: bool, 2: bool}
     */
    public static function flags(string $scope): array
    {
        $preset = self::all()[$scope] ?? self::all()[self::READ];

        return [$preset['read'], $preset['write'], $preset['owner']];
    }

    public static function fromFlags(bool $read, bool $write, bool $owner): ?string
    {
        foreach (self::all() as $id => $preset) {
            if ([$preset['read'], $preset['write'], $preset['owner']] === [$read, $write, $owner]) {
                return $id;
            }
        }

        return null;
    }

    /**
     * The scope behind a permission list such as "read, write", or null when it matches no preset.
     */
    public static function fromPermissions(string $permissions): ?string
    {
        return self::fromFlags(
            str_contains($permissions, 'read'),
            str_contains($permissions, 'write'),
            str_contains($permissions, 'owner'),
        );
    }

    /**
     * How to name a permission list in the interface: the preset, or the raw permissions.
     */
    public static function describe(string $permissions): string
    {
        $scope = self::fromPermissions($permissions);

        if ($scope !== null) {
            return self::label($scope);
        }

        return $permissions === '' ? 'No access' : 'Custom ('.$permissions.')';
    }
}
