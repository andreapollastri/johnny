@extends('layouts.app')

@section('title', 'Dashboard — '.config('app.name'))

@section('content')
@php
    $formatSize = function ($bytes) {
        if ($bytes === 0) return '0 B';
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = (int) floor(log($bytes, 1024));
        $i = min($i, count($units) - 1);
        $value = $bytes / pow(1024, $i);
        return ($i === 0 ? (int) $value : number_format($value, 2)) . ' ' . $units[$i];
    };

    $formatGb = function ($bytes) {
        $gb = $bytes / pow(1024, 3);
        if ($gb < 0.01) return '< 0.01 GB';
        return number_format($gb, 2) . ' GB';
    };
@endphp

<div class="page-header">
    <h1>Dashboard</h1>
    <p class="subtitle">Storage overview and quick links.</p>
</div>

@if ($error)
    <div class="errors">{{ $error }}</div>
@endif

<div class="stat-grid">
    <div class="card stat-card">
        <div class="stat-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
        </div>
        <div class="stat-label">Buckets</div>
        <div class="stat-value">{{ $bucketCount }}</div>
    </div>
    <div class="card stat-card">
        <div class="stat-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
        </div>
        <div class="stat-label">Storage Used</div>
        <div class="stat-value">{{ $formatGb($totalSize) }}</div>
    </div>
    <div class="card stat-card">
        <div class="stat-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
        </div>
        <div class="stat-label">Disk Usage</div>
        <div class="stat-value">{{ number_format($diskUsedPercent, 2) }}%</div>
        <div class="stat-sub">of {{ $formatGb($diskTotal) }} total</div>
    </div>
</div>


@if (count($topBucketsBySize) > 0)
    <div class="card">
        <h2>Top {{ count($topBucketsBySize) }} Buckets by Size</h2>
        <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Bucket</th>
                    <th style="text-align: right;">Size</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($topBucketsBySize as $i => $b)
                    <tr>
                        <td class="muted">{{ $i + 1 }}</td>
                        <td>
                            <a href="{{ route('buckets.show', $b['name']) }}">{{ $b['name'] }}</a>
                        </td>
                        <td class="muted fm-size" style="text-align: right;">{{ $formatSize($b['size']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </div>
    </div>
@endif

@if (count($topBucketsByCount) > 0)
    <div class="card">
        <h2>Top {{ count($topBucketsByCount) }} Buckets by Number of Files</h2>
        <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Bucket</th>
                    <th style="text-align: right;">Files</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($topBucketsByCount as $i => $b)
                    <tr>
                        <td class="muted">{{ $i + 1 }}</td>
                        <td>
                            <a href="{{ route('buckets.show', $b['name']) }}">{{ $b['name'] }}</a>
                        </td>
                        <td class="muted fm-size" style="text-align: right;">{{ number_format($b['count']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </div>
    </div>
@endif
@endsection
