@extends('layouts.app')

@use('App\Support\Format')

@section('title', 'Dashboard — '.config('app.name'))

@section('content')
<div class="page-header page-header-row">
    <div>
        <h1>Dashboard</h1>
        <p class="subtitle">Storage overview for this Johnny node.</p>
    </div>
    <div class="page-header-actions">
        @if ($computedAt)
            <span class="muted text-xs" title="{{ $computedAt->toIso8601String() }}">Updated {{ $computedAt->diffForHumans() }}</span>
        @endif
        <a href="{{ route('dashboard', ['refresh' => 1]) }}" class="btn btn-ghost btn-sm">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-2.64-6.36L21 8"/><path d="M21 3v5h-5"/></svg>
            Refresh
        </a>
    </div>
</div>

@if ($error)
    <div class="errors">{{ $error }}</div>
@endif

<div class="stat-grid">
    <a href="{{ route('buckets.index') }}" class="card stat-card stat-card-link">
        <div class="stat-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
        </div>
        <div class="stat-label">Buckets</div>
        <div class="stat-value">{{ number_format($bucketCount) }}</div>
        <div class="stat-sub">{{ number_format($keyCount) }} access {{ Str::plural('key', $keyCount) }}</div>
    </a>
    <div class="card stat-card">
        <div class="stat-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
        </div>
        <div class="stat-label">Objects</div>
        <div class="stat-value">{{ number_format($totalObjects) }}</div>
        <div class="stat-sub">across all buckets</div>
    </div>
    <div class="card stat-card">
        <div class="stat-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
        </div>
        <div class="stat-label">Stored data</div>
        <div class="stat-value">{{ Format::bytes($totalSize) }}</div>
        <div class="stat-sub">sum of object sizes</div>
    </div>
    <div class="card stat-card">
        <div class="stat-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/><path d="M3 12c0 1.66 4 3 9 3s9-1.34 9-3"/></svg>
        </div>
        <div class="stat-label">Disk</div>
        <div class="stat-value">{{ number_format($disk['percent'], 1) }}%</div>
        <div class="meter" role="meter" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ round($disk['percent']) }}" aria-label="Disk usage">
            <span @class(['meter-fill', 'is-warn' => $disk['percent'] >= 80, 'is-danger' => $disk['percent'] >= 92]) style="width: {{ min(100, round($disk['percent'], 1)) }}%"></span>
        </div>
        <div class="stat-sub" title="{{ $disk['path'] }}">{{ Format::bytes($disk['free']) }} free of {{ Format::bytes($disk['total']) }}</div>
    </div>
</div>

@php
    $maxSize = max(1, $topBucketsBySize[0]['size'] ?? 0);
    $maxCount = max(1, $topBucketsByCount[0]['count'] ?? 0);
@endphp

@if (count($topBucketsBySize) > 0)
<div class="card-grid card-grid-2">
    <div class="card">
        <h2>Largest buckets</h2>
        <table class="rank-table">
            <tbody>
                @foreach ($topBucketsBySize as $b)
                    <tr>
                        <td class="rank-name"><a href="{{ route('buckets.show', $b['name']) }}">{{ $b['name'] }}</a></td>
                        <td class="rank-bar"><span class="bar"><span style="width: {{ round($b['size'] / $maxSize * 100, 1) }}%"></span></span></td>
                        <td class="rank-value">{{ Format::bytes($b['size']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="card">
        <h2>Most objects</h2>
        <table class="rank-table">
            <tbody>
                @foreach ($topBucketsByCount as $b)
                    <tr>
                        <td class="rank-name"><a href="{{ route('buckets.show', $b['name']) }}">{{ $b['name'] }}</a></td>
                        <td class="rank-bar"><span class="bar"><span style="width: {{ round($b['count'] / $maxCount * 100, 1) }}%"></span></span></td>
                        <td class="rank-value">{{ number_format($b['count']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@elseif (! $error)
<div class="card">
    <div class="empty-state">
        <p>No buckets yet. <a href="{{ route('buckets.index') }}">Create your first bucket</a>.</p>
    </div>
</div>
@endif
@endsection
