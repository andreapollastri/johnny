@extends('layouts.app')

@use('App\Support\Format')

@section('title', 'Access keys — '.config('app.name'))

@section('crumbs')
    <span>Access keys</span>
@endsection

@section('content')
<div class="page-head">
    <div>
        <h1 class="page-title">Access keys</h1>
        <p class="page-sub">S3 credentials for your applications. Each key works on the buckets you choose, or on all of them, with one scope: read only, read and write, write only or full control.</p>
    </div>
</div>

@if ($newKey = session('new_key'))
    @include('partials.new-key', ['newKey' => $newKey])
@endif

@php
    $chosenAccess = old('access', count($buckets) > 0 ? 'buckets' : 'none');
    $chosenBuckets = (array) old('buckets', []);
@endphp

<section class="card" id="create">
    <h2>Create an access key</h2>
    <form method="POST" action="{{ route('keys.store') }}">
        @csrf
        <div class="form-grid">
            <div class="field">
                <label for="key_name">Name</label>
                <input type="text" id="key_name" name="name" class="input-mono" value="{{ old('name') }}" placeholder="my-app" required maxlength="128" pattern="[a-zA-Z0-9][a-zA-Z0-9._\-]*" title="Letters, digits, dot, dash and underscore">
                <p class="field-hint">Name it after the application that will use it: one key per application makes it easy to revoke just one.</p>
            </div>
            <div class="field" data-show-if="access=all,buckets">
                <label for="key_scope">What it may do</label>
                <select id="key_scope" name="scope">
                    @foreach ($scopes as $value => $scope)
                        <option value="{{ $value }}" @selected(old('scope', 'read-write') === $value)>{{ $scope['label'] }}</option>
                    @endforeach
                </select>
                @include('partials.scope-legend')
            </div>
            <div class="field field-wide">
                <span class="field-label" id="access-label">Where it works</span>
                <fieldset class="seg" aria-labelledby="access-label">
                    <label class="seg-option">
                        <input type="radio" name="access" value="buckets" @checked($chosenAccess === 'buckets') @disabled(count($buckets) === 0)>
                        <strong>Selected buckets</strong>
                        <span>Only the buckets you pick below.</span>
                    </label>
                    <label class="seg-option">
                        <input type="radio" name="access" value="all" @checked($chosenAccess === 'all')>
                        <strong>All buckets</strong>
                        <span>Every bucket, including the ones created later.</span>
                    </label>
                    <label class="seg-option">
                        <input type="radio" name="access" value="none" @checked($chosenAccess === 'none')>
                        <strong>No bucket yet</strong>
                        <span>Grant access later, from a bucket.</span>
                    </label>
                </fieldset>
            </div>
            <div class="field field-wide" data-show-if="access=buckets">
                <span class="field-label" id="buckets-label">Buckets</span>
                <div class="pick" role="group" aria-labelledby="buckets-label">
                    @foreach ($buckets as $name)
                        <label class="pick-option">
                            <input type="checkbox" name="buckets[]" value="{{ $name }}" @checked(in_array($name, $chosenBuckets, true))>
                            <span>{{ $name }}</span>
                        </label>
                    @endforeach
                </div>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Create key</button>
        </div>
    </form>
</section>

@if (count($keys) > 0)
<section class="card card-flush">
    <div class="card-head">
        <h2>All keys <span class="count">{{ count($keys) }}</span></h2>
    </div>
    <div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>Name</th>
                <th>Key ID</th>
                <th>Access</th>
                <th class="hide-sm">Created</th>
                <th class="col-actions"><span class="sr-only">Actions</span></th>
            </tr>
        </thead>
        <tbody>
        @foreach ($keys as $k)
            <tr>
                <td><a href="{{ route('keys.show', $k['id']) }}" class="cell-main key-name">{{ $k['name'] }}</a></td>
                <td>
                    <span class="inline-copy">
                        <code class="text-xs">{{ $k['id'] }}</code>
                        <button type="button" class="btn btn-ghost btn-xs" data-copy="{{ $k['id'] }}" aria-label="Copy key ID">Copy</button>
                    </span>
                </td>
                <td>
                    @if (isset($global[$k['id']]))
                        <span class="badge">All buckets</span>
                        <span class="cell-sub">{{ $global[$k['id']]->accessLabel() }}</span>
                    @else
                        <span class="muted text-sm">Per bucket</span>
                    @endif
                </td>
                <td class="muted text-sm hide-sm">{{ Format::date($k['created'], false) }}</td>
                <td class="col-actions">
                    <a href="{{ route('keys.show', $k['id']) }}" class="btn btn-sm">Manage</a>
                    <form method="POST" action="{{ route('keys.destroy') }}" class="inline-form" data-confirm="Permanently delete key {{ $k['name'] }} ({{ $k['id'] }})? Applications using it will lose access.">
                        @csrf
                        @method('DELETE')
                        <input type="hidden" name="key_id" value="{{ $k['id'] }}">
                        <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                    </form>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
    </div>
</section>
@elseif ($error)
<div class="flash flash-err" role="alert"><span>{{ $error }}</span></div>
@else
<div class="card">
    <div class="empty">
        <h3>No access keys yet</h3>
        <p>Create one above for each application that reads or writes files.</p>
    </div>
</div>
@endif
@endsection
