<?php

namespace App\Services;

use App\Models\GlobalKey;
use App\Support\KeyScopes;
use Illuminate\Support\Facades\Cache;

/**
 * Access keys that reach every bucket.
 *
 * Garage grants permissions per bucket and has no notion of "all buckets", so a global key is a
 * record here plus one grant per bucket. New buckets get the grant when they are created from the
 * panel or the API; buckets created elsewhere (the CLI, an S3 client) are picked up by reconcile().
 */
class GlobalKeys
{
    /** Cache key: names of the buckets the global keys were already granted on. */
    public const COVERED = 'johnny.global-keys.covered';

    public function __construct(
        private JohnnyCliService $johnny
    ) {}

    /**
     * @return array<string, GlobalKey> Global keys by key id.
     */
    public function all(): array
    {
        try {
            return GlobalKey::query()->get()->keyBy('key_id')->all();
        } catch (\Throwable) {
            return [];
        }
    }

    public function find(string $keyId): ?GlobalKey
    {
        return GlobalKey::query()->where('key_id', $keyId)->first();
    }

    /**
     * Make a key global, or change the scope of one that already is: it gets exactly the
     * permissions of the scope on every bucket. A key that was just created has no grants to
     * take away, which saves one CLI call per bucket.
     *
     * @return array{buckets: int, failed: list<string>}
     */
    public function enable(string $keyId, string $name, string $scope, bool $fresh = false): array
    {
        GlobalKey::query()->updateOrCreate(['key_id' => $keyId], ['name' => $name, 'scope' => $scope]);

        $buckets = $this->johnny->listAllBucketNamesFromGarage();
        $failed = [];

        foreach ($buckets as $bucket) {
            $result = $fresh
                ? $this->johnny->bucketAllow($bucket, $keyId, ...KeyScopes::flags($scope))
                : $this->johnny->setKeyScope($bucket, $keyId, $scope);

            if (! $result->successful()) {
                $failed[] = $bucket;
            }
        }

        Cache::forever(self::COVERED, $buckets);

        return ['buckets' => count($buckets) - count($failed), 'failed' => $failed];
    }

    /**
     * Stop treating a key as global. The grants it has stay as they are: they can be changed
     * bucket by bucket afterwards.
     */
    public function disable(string $keyId): void
    {
        GlobalKey::query()->where('key_id', $keyId)->delete();
    }

    /**
     * Grant the global keys on buckets that appeared since the last run. With `$all`, on every
     * bucket again (after a restore, or when a grant was removed by hand).
     *
     * @return int How many buckets were granted.
     */
    public function reconcile(bool $all = false): int
    {
        $keys = $this->all();

        if ($keys === []) {
            return 0;
        }

        $buckets = $this->johnny->listAllBucketNamesFromGarage();
        $covered = $all ? [] : (array) Cache::get(self::COVERED, []);
        $new = array_values(array_diff($buckets, $covered));

        foreach ($new as $bucket) {
            $this->johnny->grantGlobalKeys($bucket);
        }

        // Only the buckets that exist now: one deleted and created again must be granted again.
        Cache::forever(self::COVERED, $buckets);

        return count($new);
    }
}
