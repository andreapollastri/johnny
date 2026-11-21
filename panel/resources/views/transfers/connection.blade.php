@extends('layouts.app')

@use('App\Support\Format')

@section('title', $connection->name.' — '.config('app.name'))

@section('crumbs')
    <a href="{{ route('transfers.index') }}">Transfers</a>
    <span class="sep">/</span>
    <span>{{ $connection->name }}</span>
@endsection

@section('content')
@php
    $importable = fn (string $name) => (bool) preg_match('/^[a-z0-9][a-z0-9._-]{1,254}$/', strtolower($name));
@endphp

<div class="page-head">
    <div>
        <h1 class="page-title">
            {{ $connection->name }}
            @if ($connection->reachable() === false)
                <span class="badge badge-err">not reachable</span>
            @elseif ($connection->reachable())
                <span class="badge badge-ok">connected</span>
            @endif
        </h1>
        <p class="page-sub">{{ $connection->providerLabel() }} at <span class="mono">{{ $connection->location() }}</span>, {{ $connection->isSftp() ? 'user' : 'key' }} <span class="mono">{{ $connection->maskedKey() }}</span>@if ($connection->checked_at), checked {{ $connection->checked_at->diffForHumans() }}@endif</p>
    </div>
    <div class="page-actions">
        <form method="POST" action="{{ route('connections.check', $connection) }}" class="inline-form">
            @csrf
            <button type="submit" class="btn">Check connection</button>
        </form>
        <button type="button" class="btn" data-toggle="#connection-edit" aria-expanded="false">Edit</button>
        <form method="POST" action="{{ route('connections.destroy', $connection) }}" class="inline-form" data-confirm="Delete the connection &quot;{{ $connection->name }}&quot;? Its past transfers stay in the history; nothing is deleted at the provider.">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn btn-danger">Delete</button>
        </form>
    </div>
</div>

@if ($connection->check_error)
    <div class="flash flash-err" role="alert"><span>The last check failed: {{ $connection->check_error }}</span></div>
@endif

<section class="card" id="connection-edit" hidden>
    <h2>Edit connection</h2>
    <form method="POST" action="{{ route('connections.update', $connection) }}" data-provider-form='@json($providers)'>
        @csrf
        @method('PUT')
        @include('partials.connection-form')
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save and check</button>
        </div>
    </form>
</section>

<section class="card card-flush">
    <div class="card-head">
        <h2>{{ $connection->isSftp() ? 'Folders on the server' : 'Buckets at '.$connection->providerLabel() }} <span class="count">{{ count($remoteBuckets) }}</span></h2>
        <a href="{{ route('transfers.create', ['connection' => $connection->id, 'direction' => 'import']) }}" class="btn btn-sm">Import a {{ $connection->containerWord() }} by name</a>
    </div>
    @if ($listError)
        <div class="empty">
            <h3>The {{ $connection->containerWord() }}s could not be listed</h3>
            <p>{{ $listError }}</p>
        </div>
    @elseif (empty($remoteBuckets))
        <div class="empty">
            <p>{{ $connection->isSftp() ? 'The folder this user lands in has no folders yet. Type a path in a new transfer instead.' : 'This account has no buckets the key can see.' }}</p>
        </div>
    @else
        <form method="POST" action="{{ route('connections.import', $connection) }}" data-confirm="Import the selected buckets? Each one is copied into a Johnny bucket of the same name, created if needed. Files already there with the same content are skipped." data-confirm-title="Import buckets" data-confirm-label="Import">
            @csrf
            <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th class="col-check"><input type="checkbox" data-check-all=".remote-check" aria-label="Select every bucket"></th>
                        <th>{{ ucfirst($connection->containerWord()) }}</th>
                        <th>Created</th>
                        <th class="col-actions"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                @foreach ($remoteBuckets as $remote)
                    <tr>
                        <td class="col-check">
                            @if ($importable($remote['name']))
                                <input type="checkbox" class="remote-check" name="buckets[]" value="{{ $remote['name'] }}" aria-label="Select {{ $remote['name'] }}">
                            @endif
                        </td>
                        <td>
                            <span class="cell-main mono">{{ $remote['name'] }}</span>
                            @if (in_array(strtolower($remote['name']), $localBuckets, true))
                                <span class="badge badge-muted" title="A bucket with this name exists on Johnny: an import adds to it">on Johnny too</span>
                            @endif
                        </td>
                        <td class="muted text-sm">{{ Format::date($remote['created'], false) }}</td>
                        <td class="col-actions">
                            <a href="{{ route('transfers.create', ['connection' => $connection->id, 'direction' => 'import', 'remote_bucket' => $remote['name']]) }}" class="btn btn-ghost btn-sm">Import with options</a>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            </div>
            <div class="card-foot">
                <button type="submit" class="btn btn-primary" data-checked-count=".remote-check" disabled>Import selected</button>
                <span class="muted text-sm">Copies each {{ $connection->containerWord() }} into a Johnny bucket of the same name.</span>
            </div>
        </form>
    @endif
</section>

<section class="card">
    <h2>Export a bucket to {{ $connection->name }}</h2>
    @if (empty($localBuckets))
        <p class="muted">There is no bucket on Johnny to export yet.</p>
    @else
        <form method="GET" action="{{ route('transfers.create') }}" class="form-row">
            <input type="hidden" name="connection" value="{{ $connection->id }}">
            <input type="hidden" name="direction" value="export">
            <select name="local_bucket" aria-label="Bucket to export">
                @foreach ($localBuckets as $local)
                    <option value="{{ $local }}">{{ $local }}</option>
                @endforeach
            </select>
            <button type="submit" class="btn">Choose where it goes</button>
        </form>
    @endif
</section>

@if ($transfers->isNotEmpty())
    <section class="card card-flush">
        <div class="card-head">
            <h2>Latest transfers with this connection</h2>
        </div>
        <div class="table-wrap">
        <table>
            <tbody>
            @foreach ($transfers as $transfer)
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

@push('scripts')
    <script src="{{ asset('js/transfers.js') }}?v={{ filemtime(public_path('js/transfers.js')) }}" defer></script>
    @if ($errors->hasAny(['name', 'provider', 'endpoint', 'region', 'access_key_id', 'secret_access_key', 'host', 'port', 'username', 'password', 'private_key']))
        <script>document.addEventListener('DOMContentLoaded', function () { document.getElementById('connection-edit').hidden = false; });</script>
    @endif
@endpush
@endsection
