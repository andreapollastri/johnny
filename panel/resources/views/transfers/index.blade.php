@extends('layouts.app')

@use('App\Models\Transfer')

@section('title', 'Transfers — '.config('app.name'))

@section('crumbs')
    <span>Transfers</span>
@endsection

@section('content')
@php
    // The page keeps asking for news while something runs, or while a sync may start a run.
    $anyActive = $transfers->contains(fn (Transfer $candidate) => $candidate->isActive())
        || $syncs->contains(fn ($candidate) => $candidate->enabled || $candidate->latestRun?->isActive());
@endphp

<div class="page-head">
    <div>
        <h1 class="page-title">Transfers</h1>
        <p class="page-sub">Bring buckets in from another S3 provider or an SFTP server, or send yours out to one: once, or kept in sync on a schedule. Everything runs on the server: you can close this page and come back.</p>
    </div>
    <div class="page-actions">
        <button type="button" class="btn" data-dialog-open="#connection-dialog">Add a connection</button>
        @if ($connections->isNotEmpty())
            <a href="{{ route('transfers.create', ['schedule' => 'repeat']) }}" class="btn">New sync</a>
            <a href="{{ route('transfers.create') }}" class="btn btn-primary">New transfer</a>
        @endif
    </div>
</div>

@unless ($rcloneAvailable)
    <div class="flash flash-err" role="alert">
        <span>Transfers are run by rclone, which does not answer on this server. Install it with <code>sudo bash scripts/install-rclone.sh</code>, or set <code>JOHNNY_RCLONE_BINARY</code> in <code>panel/.env</code>.</span>
    </div>
@endunless

@if ($connections->isEmpty())
    <section class="card">
        <h2>Move a bucket in three steps</h2>
        <ol class="steps mt-2">
            <li><strong>Connect the other provider</strong><span>Amazon S3, Cloudflare R2, DigitalOcean Spaces, Backblaze B2, Wasabi, Hetzner, Scaleway, MinIO, another Johnny, or anything that speaks S3.</span></li>
            <li><strong>Pick a bucket and a direction</strong><span>Import it into Johnny, or export one of yours. Try a dry run first to see what would be copied.</span></li>
            <li><strong>Let it run</strong><span>Files are copied in the background with their folders. Running it again later only copies what changed.</span></li>
        </ol>
        <div class="form-actions">
            <button type="button" class="btn btn-primary" data-dialog-open="#connection-dialog">Add a connection</button>
        </div>
    </section>
@else
    <section class="card">
        <div class="card-head">
            <h2>Connections <span class="count">{{ $connections->count() }}</span></h2>
        </div>
        <div class="conn-grid">
            @foreach ($connections as $connection)
                <a href="{{ route('connections.show', $connection) }}" class="conn">
                    <span class="conn-name">
                        {{ $connection->name }}
                        @if ($connection->reachable() === false)
                            <span class="badge badge-err">not reachable</span>
                        @elseif ($connection->reachable())
                            <span class="badge badge-ok">connected</span>
                        @endif
                    </span>
                    <span class="conn-meta">{{ $connection->providerLabel() }}</span>
                    <span class="conn-meta mono">{{ $connection->location() }}</span>
                </a>
            @endforeach
        </div>
    </section>
@endif

<div data-transfers-live="{{ route('transfers.status') }}" data-active="{{ $anyActive ? '1' : '0' }}">
@if ($syncs->isNotEmpty())
    @unless ($schedulerRunning)
        <div class="flash flash-err" role="alert">
            <span>The scheduler is not running, so syncs only start while this page is open. Run <code>sudo johnny update apply --force</code> to install it, or add <code>* * * * * www-data php {{ base_path('artisan') }} schedule:run</code> to <code>/etc/cron.d/johnny-panel</code>.</span>
        </div>
    @endunless
    <section class="card card-flush">
        <div class="card-head">
            <h2>Syncs <span class="count">{{ $syncs->count() }}</span></h2>
        </div>
        <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>From, to</th>
                    <th>Repeats</th>
                    <th>Last run</th>
                    <th class="hide-sm">Next run</th>
                    <th class="col-actions"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
            @foreach ($syncs as $sync)
                @php
                    $transfer = $sync->latestRun;
                @endphp
                <tr @if ($transfer) data-transfer="{{ $transfer->id }}" @endif>
                    <td>
                        <a href="{{ route('syncs.show', $sync) }}" class="cell-main">{{ $sync->sourceLabel() }}</a>
                        <span class="cell-sub">{{ $sync->directionLabel() }}, to {{ $sync->destinationLabel() }}{{ $sync->mode === Transfer::SYNC ? ', mirror' : '' }}</span>
                    </td>
                    <td class="nowrap">{{ $sync->intervalLabel() }}</td>
                    <td>
                        @if ($transfer)
                            @include('partials.transfer-status')
                            <span class="cell-sub" title="{{ $transfer->created_at }}">{{ $transfer->created_at->diffForHumans() }}</span>
                        @else
                            <span class="muted">Not yet</span>
                        @endif
                    </td>
                    <td class="hide-sm nowrap">
                        @if (! $sync->enabled)
                            <span class="badge badge-muted">Paused</span>
                        @elseif ($transfer?->isActive())
                            <span class="muted">When this run ends</span>
                        @else
                            <span title="{{ $sync->next_run_at }}">{{ $sync->next_run_at?->isFuture() ? $sync->next_run_at->diffForHumans() : 'Any moment' }}</span>
                        @endif
                    </td>
                    <td class="col-actions">
                        <form method="POST" action="{{ route('syncs.toggle', $sync) }}" class="inline-form">
                            @csrf
                            <button type="submit" class="btn btn-sm">{{ $sync->enabled ? 'Pause' : 'Resume' }}</button>
                        </form>
                        <a href="{{ route('syncs.show', $sync) }}" class="btn btn-ghost btn-sm">Details</a>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
        </div>
    </section>
@endif

@if ($transfers->isNotEmpty())
    <section class="card card-flush">
        <div class="card-head">
            <h2>History <span class="count">{{ $transfers->count() }}</span></h2>
        </div>
        <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>From, to</th>
                    <th>State</th>
                    <th>Progress</th>
                    <th>Started</th>
                    <th class="col-actions"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
            @foreach ($transfers as $transfer)
                <tr data-transfer="{{ $transfer->id }}">
                    <td>
                        <a href="{{ route('transfers.show', $transfer) }}" class="cell-main">{{ $transfer->sourceLabel() }}</a>
                        <span class="cell-sub">to {{ $transfer->destinationLabel() }}{{ $transfer->mode === Transfer::SYNC ? ', mirror' : '' }}{{ $transfer->dry_run ? ', dry run' : '' }}</span>
                    </td>
                    <td>@include('partials.transfer-status')</td>
                    <td>
                        <div class="transfer-progress">
                            @if ($transfer->status === Transfer::RUNNING)
                                <span @class(['progress', 'is-indeterminate' => $transfer->percent() === null]) data-t="bar"><span class="progress-fill" data-t="fill" style="{{ $transfer->percent() !== null ? 'width: '.$transfer->percent().'%' : '' }}"></span></span>
                            @endif
                            <small data-t="progress">{{ $transfer->progressLine() }}</small>
                            @if ($transfer->isActive())
                                <small data-t="meta"></small>
                            @elseif ($transfer->status === Transfer::FAILED)
                                <small title="{{ $transfer->message }}">{{ Str::limit((string) $transfer->message, 80) }}</small>
                            @endif
                        </div>
                    </td>
                    <td class="muted text-sm nowrap" title="{{ $transfer->created_at }}">{{ $transfer->created_at->diffForHumans() }}</td>
                    <td class="col-actions">
                        <a href="{{ route('transfers.show', $transfer) }}" class="btn btn-ghost btn-sm">Details</a>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
        </div>
    </section>
@endif
</div>

<dialog id="connection-dialog" aria-labelledby="connection-dialog-title" style="width: min(46rem, calc(100vw - 2rem))">
    <form method="POST" action="{{ route('connections.store') }}" data-provider-form='@json($providers)'>
        @csrf
        <div class="dialog-body">
            <h2 class="dialog-title" id="connection-dialog-title">Add a connection</h2>
            <p class="text-sm mb-1">The S3 account, or the SFTP server, that buckets are imported from or exported to. Credentials that can read (to import) or write (to export) are enough.</p>
            @include('partials.connection-form', ['connection' => null])
        </div>
        <div class="dialog-actions">
            <button type="button" class="btn" data-dialog-close>Cancel</button>
            <button type="submit" class="btn btn-primary">Save and connect</button>
        </div>
    </form>
</dialog>

@push('scripts')
    <script src="{{ asset('js/transfers.js') }}?v={{ filemtime(public_path('js/transfers.js')) }}" defer></script>
    @if ($errors->hasAny(['name', 'provider', 'endpoint', 'region', 'access_key_id', 'secret_access_key', 'host', 'port', 'username', 'password', 'private_key']))
        <script>document.addEventListener('DOMContentLoaded', function () { var d = document.getElementById('connection-dialog'); if (d && d.showModal) d.showModal(); });</script>
    @endif
@endpush
@endsection
