@extends('layouts.app')

@use('App\Models\Transfer')
@use('App\Support\Format')

@section('title', 'Sync '.$sync->local_bucket.' — '.config('app.name'))

@section('crumbs')
    <a href="{{ route('transfers.index') }}">Transfers</a>
    <span class="sep">/</span>
    <span>Sync {{ $sync->local_bucket }}</span>
@endsection

@section('content')
@php
    $latest = $runs->first();
    $running = $latest?->isActive() ?? false;
@endphp

<div class="page-head">
    <div>
        <h1 class="page-title">{{ $sync->sourceLabel() }}</h1>
        <p class="page-sub">
            Synced {{ strtolower($sync->directionLabel()) }} to <strong>{{ $sync->destinationLabel() }}</strong>,
            {{ strtolower($sync->intervalLabel()) }}.
            @unless ($sync->enabled)
                <span class="badge badge-muted">Paused</span>
            @endunless
        </p>
    </div>
    <div class="page-actions">
        <form method="POST" action="{{ route('syncs.toggle', $sync) }}" class="inline-form">
            @csrf
            <button type="submit" class="btn">{{ $sync->enabled ? 'Pause' : 'Resume' }}</button>
        </form>
        <form method="POST" action="{{ route('syncs.run', $sync) }}" class="inline-form">
            @csrf
            <button type="submit" class="btn btn-primary" @disabled($running)>Run now</button>
        </form>
    </div>
</div>

@if ($sync->enabled && ! $schedulerRunning)
    <div class="flash flash-err" role="alert">
        <span>The scheduler is not running, so this sync only starts while a Transfers page is open. Run <code>sudo johnny update apply --force</code> to install it.</span>
    </div>
@endif

<div class="grid-2">
    <section class="card">
        <h2>Schedule</h2>
        <dl class="kv">
            <dt>Last run</dt>
            <dd>{{ $sync->last_run_at ? $sync->last_run_at->diffForHumans() : 'Not yet' }}</dd>
            <dt>Next run</dt>
            <dd>
                @if (! $sync->enabled)
                    Paused
                @elseif ($running)
                    When the run in progress ends
                @else
                    {{ $sync->next_run_at?->isFuture() ? $sync->next_run_at->diffForHumans() : 'Any moment' }}
                @endif
            </dd>
            <dt>Direction</dt>
            <dd>{{ $sync->isImport() ? 'In: '.$sync->connectionName().' to Johnny' : 'Out: Johnny to '.$sync->connectionName() }}</dd>
            <dt>Provider</dt>
            <dd>{{ $sync->providerLabel() }}</dd>
        </dl>
    </section>

    <section class="card">
        <h2>Settings</h2>
        <form method="POST" action="{{ route('syncs.update', $sync) }}">
            @csrf
            @method('PUT')
            @include('partials.interval-fields', ['minutes' => $sync->interval_minutes])
            <div class="field">
                <label for="mode">How to copy</label>
                <select id="mode" name="mode">
                    <option value="copy" @selected(old('mode', $sync->mode) === 'copy')>Copy: add new and changed files</option>
                    <option value="sync" @selected(old('mode', $sync->mode) === 'sync')>Mirror: also delete what the source no longer has</option>
                </select>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Save</button>
            </div>
        </form>
    </section>
</div>

<section class="card card-flush" data-transfers-live="{{ route('transfers.status') }}" data-active="{{ $running || $sync->enabled ? '1' : '0' }}">
    <div class="card-head">
        <h2>Latest runs <span class="count">{{ $runs->count() }}</span></h2>
    </div>
    @if ($runs->isEmpty())
        <div class="empty">
            <p>This sync has not run yet.</p>
        </div>
    @else
        <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Started</th>
                    <th>State</th>
                    <th>What it moved</th>
                    <th class="hide-sm">Took</th>
                    <th class="col-actions"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
            @foreach ($runs as $transfer)
                <tr data-transfer="{{ $transfer->id }}">
                    <td class="nowrap" title="{{ $transfer->created_at }}">{{ $transfer->created_at->diffForHumans() }}</td>
                    <td>@include('partials.transfer-status')</td>
                    <td>
                        <div class="transfer-progress">
                            <small data-t="progress">{{ $transfer->progressLine() }}</small>
                            @if ($transfer->status === Transfer::FAILED)
                                <small title="{{ $transfer->message }}">{{ Str::limit((string) $transfer->message, 80) }}</small>
                            @endif
                        </div>
                    </td>
                    <td class="hide-sm muted text-sm nowrap">
                        @if ($transfer->started_at && $transfer->finished_at)
                            {{ Format::duration(abs($transfer->finished_at->getTimestamp() - $transfer->started_at->getTimestamp())) }}
                        @endif
                    </td>
                    <td class="col-actions">
                        <a href="{{ route('transfers.show', $transfer) }}" class="btn btn-ghost btn-sm">Details</a>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
        </div>
    @endif
</section>

<section class="card">
    <h2>Delete this sync</h2>
    <p class="card-sub">Stops the schedule and removes its runs from this page. The files already copied stay where they are, on both sides.</p>
    <form method="POST" action="{{ route('syncs.destroy', $sync) }}" data-confirm="Delete the sync of {{ $sync->local_bucket }}? Files already copied are not touched." data-confirm-label="Delete sync">
        @csrf
        @method('DELETE')
        <button type="submit" class="btn btn-danger">Delete sync</button>
    </form>
</section>

@push('scripts')
    <script src="{{ asset('js/transfers.js') }}?v={{ filemtime(public_path('js/transfers.js')) }}" defer></script>
@endpush
@endsection
