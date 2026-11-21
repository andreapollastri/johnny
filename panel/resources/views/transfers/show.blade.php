@extends('layouts.app')

@use('App\Models\Transfer')
@use('App\Support\Format')

@section('title', 'Transfer #'.$transfer->id.' — '.config('app.name'))

@section('crumbs')
    <a href="{{ route('transfers.index') }}">Transfers</a>
    <span class="sep">/</span>
    @if ($transfer->sync_id)
        <a href="{{ route('syncs.show', $transfer->sync_id) }}">Sync {{ $transfer->local_bucket }}</a>
        <span class="sep">/</span>
    @endif
    <span>#{{ $transfer->id }}</span>
@endsection

@section('content')
@php
    $percent = $transfer->percent();
    $finished = ! $transfer->isActive();
    $barState = match ($transfer->status) {
        Transfer::COMPLETED => 'is-done',
        Transfer::FAILED => 'is-failed',
        default => '',
    };
@endphp

<div class="page-head">
    <div>
        <h1 class="page-title">
            {{ $transfer->isImport() ? 'Import' : 'Export' }} {{ $transfer->isImport() ? $transfer->remote_bucket : $transfer->local_bucket }}
            @include('partials.transfer-status')
        </h1>
        <p class="page-sub">From <strong>{{ $transfer->sourceLabel() }}</strong> to <strong>{{ $transfer->destinationLabel() }}</strong></p>
    </div>
    <div class="page-actions">
        @if ($transfer->isActive())
            <form method="POST" action="{{ route('transfers.cancel', $transfer) }}" class="inline-form" data-confirm="Cancel this transfer? Files already copied stay where they are; running it again later picks up where it stopped." data-confirm-title="Cancel transfer" data-confirm-label="Cancel transfer" data-confirm-danger>
                @csrf
                <button type="submit" class="btn btn-danger" @disabled($transfer->cancel_requested_at !== null)>Cancel transfer</button>
            </form>
        @else
            @if ($transfer->dry_run && $transfer->status === Transfer::COMPLETED)
                <form method="POST" action="{{ route('transfers.retry', $transfer) }}" class="inline-form">
                    @csrf
                    <input type="hidden" name="for_real" value="1">
                    <button type="submit" class="btn btn-primary">Run it for real</button>
                </form>
            @endif
            <form method="POST" action="{{ route('transfers.retry', $transfer) }}" class="inline-form">
                @csrf
                <button type="submit" class="btn">Run again</button>
            </form>
            <form method="POST" action="{{ route('transfers.destroy', $transfer) }}" class="inline-form" data-confirm="Remove this transfer from the history? The files it copied are not touched.">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-ghost">Remove</button>
            </form>
        @endif
    </div>
</div>

<div data-transfer-live="{{ route('transfers.status-of', $transfer) }}" data-active="{{ $transfer->isActive() ? '1' : '0' }}">
    <section class="card">
        <span @class(['progress', $barState, 'is-indeterminate' => $transfer->isActive() && $percent === null]) data-t="bar"><span class="progress-fill" data-t="fill" style="{{ $percent !== null ? 'width: '.$percent.'%' : '' }}"></span></span>

        <dl class="run-facts">
            @if ($transfer->isActive())
                <div><dt>Done</dt><dd data-t="percent">{{ $percent === null ? '—' : $percent.'%' }}</dd></div>
                <div><dt>Speed</dt><dd data-t="speed">{{ $transfer->speed > 0 ? Format::bytes($transfer->speed).'/s' : '—' }}</dd></div>
                <div><dt>Time left</dt><dd data-t="eta">{{ $transfer->eta !== null ? Format::duration($transfer->eta) : '—' }}</dd></div>
            @else
                <div><dt>Data</dt><dd>{{ Format::bytes($transfer->bytes_done) }}@if ($transfer->bytes_total > $transfer->bytes_done)<span class="muted text-sm"> of {{ Format::bytes($transfer->bytes_total) }}</span>@endif</dd></div>
                <div><dt>Files</dt><dd>{{ number_format($transfer->files_done) }}@if ($transfer->files_total > $transfer->files_done)<span class="muted text-sm"> of {{ number_format($transfer->files_total) }}</span>@endif</dd></div>
                @if ($transfer->started_at && $transfer->finished_at)
                    <div><dt>Took</dt><dd>{{ Format::duration($transfer->started_at->diffInSeconds($transfer->finished_at)) }}</dd></div>
                @endif
            @endif
            <div><dt>Errors</dt><dd data-t="errors">{{ number_format($transfer->errors) }}</dd></div>
        </dl>

        @if ($transfer->isActive())
            <p class="muted mt-3" data-t="progress">{{ $transfer->progressLine() }}</p>
            @if ($transfer->status === Transfer::PENDING)
                <p class="notice">Waiting for its turn: only a few transfers run at the same time.</p>
            @endif
        @elseif ($transfer->message)
            <p @class(['notice', 'notice-warn' => $transfer->status === Transfer::FAILED])>{{ $transfer->message }}</p>
        @endif
    </section>

    <section class="card">
        <div class="card-head">
            <h2>Log</h2>
            <span class="muted text-sm">The last {{ \App\Services\Transfers\RcloneProgress::LOG_LINES }} lines rclone wrote</span>
        </div>
        <pre class="log-view" data-t="log">{{ empty($transfer->log) ? ($finished ? 'rclone wrote nothing.' : 'Waiting for rclone…') : implode("\n", (array) $transfer->log) }}</pre>
    </section>
</div>

<section class="card">
    <h2>Settings of this transfer</h2>
    <dl class="kv">
        <dt>Direction</dt><dd>{{ $transfer->isImport() ? 'Import into Johnny' : 'Export from Johnny' }}</dd>
        <dt>Connection</dt>
        <dd>
            @if ($transfer->remoteConnection)
                <a href="{{ route('connections.show', $transfer->remoteConnection) }}">{{ $transfer->connection_name }}</a> ({{ $transfer->providerLabel() }})
            @else
                {{ $transfer->connection_name }} ({{ $transfer->providerLabel() }}, deleted)
            @endif
        </dd>
        <dt>Bucket there</dt><dd class="mono text-sm">{{ $transfer->remote_bucket }}</dd>
        <dt>Bucket on Johnny</dt><dd class="mono text-sm"><a href="{{ route('buckets.show', $transfer->local_bucket) }}">{{ $transfer->local_bucket }}</a></dd>
        <dt>Folder</dt><dd class="mono text-sm">{{ $transfer->prefix !== '' ? $transfer->prefix : 'The whole bucket' }}</dd>
        <dt>How</dt><dd>{{ $transfer->mode === Transfer::SYNC ? 'Mirror (deletes extra files at the destination)' : 'Copy (never deletes)' }}{{ $transfer->dry_run ? ', dry run' : '' }}</dd>
        <dt>Started</dt><dd>{{ $transfer->started_at ? Format::date($transfer->started_at->toIso8601String()) : 'Not yet' }}</dd>
        <dt>Finished</dt><dd>{{ $transfer->finished_at ? Format::date($transfer->finished_at->toIso8601String()) : '—' }}</dd>
        @if ($transfer->user)
            <dt>Started by</dt><dd>{{ $transfer->user->email }}</dd>
        @endif
    </dl>
</section>

@push('scripts')
    <script src="{{ asset('js/transfers.js') }}?v={{ filemtime(public_path('js/transfers.js')) }}" defer></script>
@endpush
@endsection
