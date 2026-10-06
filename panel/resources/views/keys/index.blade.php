@extends('layouts.app')

@use('App\Support\Format')

@section('title', 'API Keys — '.config('app.name'))

@section('content')
<div class="page-header">
    <h1>Access keys</h1>
    <p class="subtitle">S3 credentials for your applications. To grant bucket access, open the bucket and use the Access keys tab.</p>
</div>

@if (session('status'))
    <div class="status">{{ session('status') }}</div>
@endif
@if ($errors->any())
    <div class="errors">{{ $errors->first() }}</div>
@endif

@if ($newKey = session('new_key'))
<div class="card card-highlight">
    <h2>New key: {{ $newKey['name'] }}</h2>
    <p class="muted text-sm">Copy the secret now — Garage will not show it again.</p>
    @if ($newKey['credentials'])
        <div class="copy-fields">
            <div class="copy-field">
                <span class="copy-label">Access key ID</span>
                <code id="new-key-id">{{ $newKey['credentials']['access_key_id'] }}</code>
                <button type="button" class="ghost sm" data-copy-from="#new-key-id">Copy</button>
            </div>
            <div class="copy-field">
                <span class="copy-label">Secret access key</span>
                <code id="new-key-secret">{{ $newKey['credentials']['secret_access_key'] }}</code>
                <button type="button" class="ghost sm" data-copy-from="#new-key-secret">Copy</button>
            </div>
            <div class="copy-field">
                <span class="copy-label">Endpoint</span>
                <code id="new-key-endpoint">{{ config('services.garage.endpoint') }}</code>
                <button type="button" class="ghost sm" data-copy-from="#new-key-endpoint">Copy</button>
            </div>
        </div>
    @else
        <pre class="raw">{{ $newKey['raw'] }}</pre>
    @endif
</div>
@endif

<div class="card">
    <h2>Create key</h2>
    <form method="POST" action="{{ route('keys.store') }}" class="form-row">
        @csrf
        <input type="text" name="name" value="{{ old('name') }}" placeholder="my-app-key" required maxlength="128" pattern="[a-zA-Z0-9][a-zA-Z0-9._\-]*" title="Letters, digits, dot, dash and underscore" aria-label="Key name">
        <button type="submit">Create key</button>
    </form>
</div>

@if (count($keys) > 0)
<div class="card">
    <div class="card-head">
        <h2>All keys <span class="count">{{ count($keys) }}</span></h2>
    </div>
    <div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>Name</th>
                <th>Key ID</th>
                <th>Created</th>
                <th class="col-actions"></th>
            </tr>
        </thead>
        <tbody>
        @foreach ($keys as $k)
            <tr>
                <td><strong class="key-name">{{ $k['name'] }}</strong></td>
                <td>
                    <span class="inline-copy">
                        <code class="text-xs">{{ $k['id'] }}</code>
                        <button type="button" class="ghost xs" data-copy="{{ $k['id'] }}" aria-label="Copy key ID">Copy</button>
                    </span>
                </td>
                <td class="muted text-sm">{{ Format::date($k['created'], false) }}</td>
                <td class="col-actions">
                    <form method="POST" action="{{ route('keys.destroy') }}" data-confirm="Permanently delete key {{ $k['name'] }} ({{ $k['id'] }})? Applications using it will lose access.">
                        @csrf
                        @method('DELETE')
                        <input type="hidden" name="key_id" value="{{ $k['id'] }}">
                        <button type="submit" class="danger sm">Delete</button>
                    </form>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
    </div>
</div>
@elseif ($error)
<div class="card">
    <h2>Key list</h2>
    <div class="errors">{{ $error }}</div>
</div>
@else
<div class="card">
    <div class="empty-state">
        <p>No custom keys yet. Create one above.</p>
    </div>
</div>
@endif
@endsection
