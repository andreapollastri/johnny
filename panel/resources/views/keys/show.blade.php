@extends('layouts.app')

@use('App\Support\Format')
@use('App\Support\KeyScopes')

@section('title', $key['name'].' — Access keys — '.config('app.name'))

@section('crumbs')
    <a href="{{ route('keys.index') }}">Access keys</a>
    <span class="sep">/</span>
    <span>{{ $key['name'] }}</span>
@endsection

@section('content')
@php
    $granted = array_column($grants, 'bucket');
    $free = array_values(array_diff($buckets, $granted));
@endphp

<div class="page-head">
    <div>
        <h1 class="page-title">{{ $key['name'] }}</h1>
        <p class="page-sub">
            <span class="inline-copy">
                <code>{{ $key['id'] }}</code>
                <button type="button" class="btn btn-ghost btn-xs" data-copy="{{ $key['id'] }}" aria-label="Copy key ID">Copy</button>
            </span>
            @if ($key['created'])
                <span class="muted">Created {{ Format::date($key['created'], false) }}</span>
            @endif
        </p>
    </div>
    <div class="page-actions">
        <form method="POST" action="{{ route('keys.destroy') }}" class="inline-form" data-confirm="Permanently delete key {{ $key['name'] }} ({{ $key['id'] }})? Applications using it will lose access.">
            @csrf
            @method('DELETE')
            <input type="hidden" name="key_id" value="{{ $key['id'] }}">
            <button type="submit" class="btn btn-danger">Delete key</button>
        </form>
    </div>
</div>

<section @class(['card', 'card-highlight' => $global !== null])>
    <h2>All buckets @if ($global)<span class="badge">{{ $global->accessLabel() }}</span>@endif</h2>
    <p class="card-sub">
        @if ($global)
            This key works on every bucket, and gets the same access on each bucket you create.
        @else
            Turn this on to give the key one scope on every bucket, including the ones created later.
        @endif
    </p>
    <form method="POST" action="{{ route('keys.all-buckets', $key['id']) }}" class="grant-form" @if ($global) data-confirm="Change the access of {{ $key['name'] }} on every bucket?" data-confirm-label="Apply" @endif>
        @csrf
        <div>
            <label for="global_scope">Access to all buckets</label>
            <select id="global_scope" name="scope">
                <option value="off" @selected($global === null)>Off: bucket by bucket</option>
                @foreach ($scopes as $value => $scope)
                    <option value="{{ $value }}" @selected($global?->scope === $value)>{{ $scope['label'] }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="btn btn-primary">Apply</button>
    </form>
    @include('partials.scope-legend')
</section>

<section class="card card-flush">
    <div class="card-head">
        <h2>Buckets <span class="count">{{ count($grants) }}</span></h2>
    </div>
    @if (! $infoKnown)
        <div class="empty">
            <p>The buckets of this key could not be read from Garage. Try again in a moment.</p>
        </div>
    @elseif ($grants === [])
        <div class="empty">
            <h3>No bucket yet</h3>
            <p>This key cannot read or write anything until a bucket grants it access.</p>
        </div>
    @else
        <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Bucket</th>
                    <th>Access</th>
                    <th class="col-actions"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
            @foreach ($grants as $grant)
                <tr>
                    <td><a href="{{ route('buckets.show', ['bucket' => $grant['bucket'], 'tab' => 'keys']) }}" class="cell-main input-mono">{{ $grant['bucket'] }}</a></td>
                    <td>
                        {{ KeyScopes::describe($grant['permissions']) }}
                        <span class="perm-chips">
                            @foreach (['read', 'write', 'owner'] as $perm)
                                <span @class(['perm-chip', 'is-on' => str_contains($grant['permissions'], $perm)])>{{ $perm }}</span>
                            @endforeach
                        </span>
                    </td>
                    <td class="col-actions">
                        @if ($global)
                            <span class="muted text-xs">Follows "All buckets"</span>
                        @else
                            <form method="POST" action="{{ route('keys.grant', $key['id']) }}" class="inline-form row-form">
                                @csrf
                                <input type="hidden" name="bucket" value="{{ $grant['bucket'] }}">
                                <select name="scope" class="input-sm" aria-label="Access to {{ $grant['bucket'] }}">
                                    @foreach ($scopes as $value => $scope)
                                        <option value="{{ $value }}" @selected(KeyScopes::fromPermissions($grant['permissions']) === $value)>{{ $scope['label'] }}</option>
                                    @endforeach
                                </select>
                                <button type="submit" class="btn btn-sm">Set</button>
                            </form>
                            <form method="POST" action="{{ route('keys.grant', $key['id']) }}" class="inline-form" data-confirm="Revoke the access of {{ $key['name'] }} to {{ $grant['bucket'] }}?" data-confirm-label="Revoke">
                                @csrf
                                <input type="hidden" name="bucket" value="{{ $grant['bucket'] }}">
                                <input type="hidden" name="scope" value="none">
                                <button type="submit" class="btn btn-danger btn-sm">Revoke</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
        </div>
    @endif

    @if ($global === null && $free !== [])
        <div class="card-foot">
            <form method="POST" action="{{ route('keys.grant', $key['id']) }}" class="grant-form">
                @csrf
                <div>
                    <label for="grant_bucket">Add a bucket</label>
                    <select id="grant_bucket" name="bucket" required>
                        @foreach ($free as $name)
                            <option value="{{ $name }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="grant_scope">Access</label>
                    <select id="grant_scope" name="scope">
                        @foreach ($scopes as $value => $scope)
                            <option value="{{ $value }}" @selected($value === 'read-write')>{{ $scope['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="btn btn-primary">Grant access</button>
            </form>
        </div>
    @endif
</section>
@endsection
