<?php

namespace App\Http\Controllers;

use App\Services\GarageS3;
use App\Services\JohnnyCliService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public const CACHE_KEY = 'johnny.dashboard.stats';

    /** Bucket stats need a full listing of every bucket: keep them for a minute. */
    private const CACHE_TTL = 60;

    public function __construct(
        private GarageS3 $garage,
        private JohnnyCliService $johnny
    ) {}

    public function __invoke(Request $request): View
    {
        if ($request->boolean('refresh')) {
            Cache::forget(self::CACHE_KEY);
        }

        $error = '';
        $stats = ['buckets' => [], 'keys' => 0, 'computed_at' => null];

        try {
            $stats = Cache::remember(self::CACHE_KEY, self::CACHE_TTL, fn () => $this->collectStats());
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        $buckets = $stats['buckets'];
        $totalSize = array_sum(array_column($buckets, 'size'));
        $totalObjects = array_sum(array_column($buckets, 'count'));

        $bySize = $buckets;
        usort($bySize, fn ($a, $b) => $b['size'] <=> $a['size']);

        $byCount = $buckets;
        usort($byCount, fn ($a, $b) => $b['count'] <=> $a['count']);

        return view('dashboard', [
            'error' => $error,
            'bucketCount' => count($buckets),
            'keyCount' => $stats['keys'],
            'totalSize' => $totalSize,
            'totalObjects' => $totalObjects,
            'disk' => $this->diskUsage(),
            'computedAt' => $stats['computed_at'] ? Carbon::parse($stats['computed_at']) : null,
            'topBucketsBySize' => array_slice($bySize, 0, 10),
            'topBucketsByCount' => array_slice($byCount, 0, 10),
        ]);
    }

    /**
     * @return array{buckets: list<array{name: string, size: int, count: int}>, keys: int, computed_at: string}
     */
    private function collectStats(): array
    {
        $buckets = [];
        foreach ($this->garage->listBuckets() as $b) {
            $buckets[] = ['name' => $b['name'], ...$this->garage->getBucketStats($b['name'])];
        }

        return [
            'buckets' => $buckets,
            'keys' => count($this->johnny->userKeys($this->johnny->listAllKeys())),
            'computed_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Usage of the filesystem that holds Garage data (falls back to / when the path is absent).
     *
     * @return array{path: string, total: int, used: int, free: int, percent: float}
     */
    private function diskUsage(): array
    {
        $path = (string) config('services.garage.data_dir', '/var/lib/johnny/data');
        if (! @is_dir($path)) {
            $path = '/';
        }

        $total = (int) (@disk_total_space($path) ?: 0);
        $free = (int) (@disk_free_space($path) ?: 0);
        $used = max(0, $total - $free);

        return [
            'path' => $path,
            'total' => $total,
            'used' => $used,
            'free' => $free,
            'percent' => $total > 0 ? $used / $total * 100 : 0.0,
        ];
    }
}
