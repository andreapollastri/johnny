@extends('layouts.app')

@use('App\Support\Format')

@section('title', 'Dashboard — '.config('app.name'))

@section('crumbs')
    <span>Dashboard</span>
@endsection

@section('content')
@php
    $maxSize = max(1, $topBucketsBySize[0]['size'] ?? 0);
    $maxCount = max(1, $topBucketsByCount[0]['count'] ?? 0);
    $diskState = $disk['percent'] >= 92 ? 'is-danger' : ($disk['percent'] >= 80 ? 'is-warn' : '');
    $diskWord = $disk['percent'] >= 92 ? 'almost full' : ($disk['percent'] >= 80 ? 'filling up' : 'used');
@endphp

<div class="page-head">
    <div>
        <h1 class="page-title">Dashboard</h1>
        <p class="page-sub">What this Johnny node stores, and how much room is left for it.</p>
    </div>
    <div class="page-actions">
        @if ($computedAt)
            <span class="muted text-xs" title="{{ $computedAt->toIso8601String() }}">Counted {{ $computedAt->diffForHumans() }}</span>
        @endif
        <a href="{{ route('dashboard', ['refresh' => 1]) }}" class="btn btn-sm">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-2.64-6.36L21 8"/><path d="M21 3v5h-5"/></svg>
            Refresh
        </a>
    </div>
</div>

@if ($error)
    <div class="flash flash-err" role="alert"><span>{{ $error }}</span></div>
@endif

<section class="overview" aria-label="Storage">
    <div class="overview-main">
        <div class="hero-label">Stored data</div>
        <div class="hero-figure">{{ Format::bytes($totalSize) }}</div>
        <span class="meter {{ $diskState }}" role="meter" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ round($disk['percent']) }}" aria-label="Disk usage">
            <span class="meter-fill" style="width: {{ min(100, round($disk['percent'], 1)) }}%"></span>
        </span>
        <div class="meter-caption" title="{{ $disk['path'] }}">
            <span><strong>Disk {{ number_format($disk['percent'], 1) }}% {{ $diskWord }}</strong></span>
            <span>{{ Format::bytes($disk['free']) }} free of {{ Format::bytes($disk['total']) }}</span>
        </div>
    </div>
    <div class="overview-side">
        <a href="{{ route('buckets.index') }}" class="figure">
            <span class="figure-label">Buckets</span>
            <span class="figure-value">{{ number_format($bucketCount) }}</span>
        </a>
        <div class="figure">
            <span class="figure-label">Objects</span>
            <span class="figure-value">{{ number_format($totalObjects) }}</span>
        </div>
        <a href="{{ route('keys.index') }}" class="figure" title="{{ number_format($keyCount) }} access {{ Str::plural('key', $keyCount) }}">
            <span class="figure-label">Access keys</span>
            <span class="figure-value">{{ number_format($keyCount) }}</span>
        </a>
    </div>
</section>

@if (count($topBucketsBySize) > 0)
<div class="grid-2">
    <section class="card">
        <h2>Largest buckets</h2>
        <div class="rank">
            @foreach ($topBucketsBySize as $b)
                <div class="rank-row" title="{{ $b['name'] }}: {{ Format::bytes($b['size']) }}, {{ $totalSize > 0 ? round($b['size'] / $totalSize * 100, 1) : 0 }}% of the stored data">
                    <span class="rank-name"><a href="{{ route('buckets.show', $b['name']) }}">{{ $b['name'] }}</a></span>
                    <span class="rank-bar"><span style="width: {{ round($b['size'] / $maxSize * 100, 1) }}%"></span></span>
                    <span class="rank-value">{{ Format::bytes($b['size']) }}</span>
                </div>
            @endforeach
        </div>
    </section>
    <section class="card">
        <h2>Most objects</h2>
        <div class="rank">
            @foreach ($topBucketsByCount as $b)
                <div class="rank-row" title="{{ $b['name'] }}: {{ number_format($b['count']) }} objects">
                    <span class="rank-name"><a href="{{ route('buckets.show', $b['name']) }}">{{ $b['name'] }}</a></span>
                    <span class="rank-bar"><span style="width: {{ round($b['count'] / $maxCount * 100, 1) }}%"></span></span>
                    <span class="rank-value">{{ number_format($b['count']) }}</span>
                </div>
            @endforeach
        </div>
    </section>
</div>
@elseif (! $error)
<div class="card">
    <div class="empty">
        <h3>No buckets yet</h3>
        <p>A bucket holds your files. Create one, or bring one over from another S3 provider.</p>
        <a href="{{ route('buckets.index') }}#create" class="btn btn-primary">Create a bucket</a>
        <a href="{{ route('transfers.index') }}" class="btn">Import a bucket</a>
    </div>
</div>
@endif

@if ($recentTransfers->isNotEmpty())
<section class="card card-flush">
    <div class="card-head">
        <h2>Recent transfers</h2>
        <a href="{{ route('transfers.index') }}" class="text-sm">All transfers</a>
    </div>
    <div class="table-wrap">
    <table>
        <tbody>
        @foreach ($recentTransfers as $transfer)
            <tr>
                <td>
                    <a href="{{ route('transfers.show', $transfer) }}" class="cell-main">{{ $transfer->sourceLabel() }}</a>
                    <span class="cell-sub">to {{ $transfer->destinationLabel() }}</span>
                </td>
                <td>@include('partials.transfer-status')</td>
                <td class="muted text-sm hide-sm">{{ $transfer->progressLine() }}</td>
                <td class="muted text-sm col-num">{{ $transfer->created_at->diffForHumans() }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    </div>
</section>
@endif
@endsection
