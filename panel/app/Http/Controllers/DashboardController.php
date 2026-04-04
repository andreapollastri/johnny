<?php

namespace App\Http\Controllers;

use App\Services\GarageS3;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(
        private GarageS3 $garage
    ) {}

    public function __invoke(): View
    {
        $error = '';
        $buckets = [];
        $bucketStats = [];
        $totalSize = 0;

        try {
            $buckets = $this->garage->listBuckets();

            foreach ($buckets as $b) {
                $stats = $this->garage->getBucketStats($b['name']);
                $bucketStats[] = ['name' => $b['name'], 'size' => $stats['size'], 'count' => $stats['count']];
                $totalSize += $stats['size'];
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        $bySize = $bucketStats;
        usort($bySize, fn ($a, $b) => $b['size'] <=> $a['size']);

        $byCount = $bucketStats;
        usort($byCount, fn ($a, $b) => $b['count'] <=> $a['count']);

        $diskTotal = @disk_total_space('/') ?: 0;
        $diskUsedPercent = $diskTotal > 0 ? ($totalSize / $diskTotal) * 100 : 0;

        return view('dashboard', [
            'error' => $error,
            'bucketCount' => count($buckets),
            'totalSize' => $totalSize,
            'diskTotal' => $diskTotal,
            'diskUsedPercent' => $diskUsedPercent,
            'topBucketsBySize' => array_slice($bySize, 0, 10),
            'topBucketsByCount' => array_slice($byCount, 0, 10),
        ]);
    }
}
