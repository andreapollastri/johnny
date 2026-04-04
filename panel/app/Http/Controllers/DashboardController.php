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
        $bucketSizes = [];
        $totalSize = 0;

        try {
            $buckets = $this->garage->listBuckets();

            foreach ($buckets as $b) {
                $size = $this->garage->getBucketSize($b['name']);
                $bucketSizes[] = ['name' => $b['name'], 'size' => $size];
                $totalSize += $size;
            }

            usort($bucketSizes, fn ($a, $b) => $b['size'] <=> $a['size']);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        $diskTotal = @disk_total_space('/') ?: 0;
        $diskUsedPercent = $diskTotal > 0 ? ($totalSize / $diskTotal) * 100 : 0;

        return view('dashboard', [
            'error' => $error,
            'bucketCount' => count($buckets),
            'totalSize' => $totalSize,
            'diskTotal' => $diskTotal,
            'diskUsedPercent' => $diskUsedPercent,
            'topBuckets' => array_slice($bucketSizes, 0, 10),
        ]);
    }
}
