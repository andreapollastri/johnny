@extends('layouts.app')

@section('title', 'API Keys — '.config('app.name'))

@section('content')
<div class="page-header">
    <h1>API Keys</h1>
    <p class="subtitle">Garage keys need explicit bucket permissions. After creating a key, grant access below.</p>
</div>

@if (session('status'))
    <div class="status">{{ session('status') }}</div>
@endif
@if ($errors->any())
    <div class="errors">{{ $errors->first() }}</div>
@endif

@if (session('key_create_output'))
<div class="card">
    <h2>New key created</h2>
    <p class="muted text-sm">Copy the secret now — it will not be shown again.</p>
    <pre class="raw">{{ session('key_create_output') }}</pre>
</div>
@endif

<div class="card">
    <h2>Create key</h2>
    <form method="POST" action="{{ route('keys.store') }}">
        @csrf
        <div class="form-row" style="margin-bottom:0.75rem;">
            <input type="text" name="name" value="{{ old('name') }}" placeholder="my-app-key" required>
            <button type="submit">Create key</button>
        </div>
        <label style="display:flex; align-items:center; gap:0.4rem; cursor:pointer;">
            <input type="checkbox" name="allow_all_buckets" value="1" checked>
            <span class="text-sm">Grant read/write/owner on all existing buckets</span>
        </label>
    </form>
</div>

<div class="card">
    <h2>Grant / Revoke bucket permissions</h2>
    <p class="muted text-sm" style="margin-bottom:0.75rem;">Enter the key name exactly as shown in the list below, select a bucket, and choose permissions.</p>
    <form method="POST" action="{{ route('keys.allow') }}" id="permForm">
        @csrf
        <div class="form-row" style="margin-bottom:0.5rem;">
            <div>
                <label for="perm_key_name">Key name</label>
                <input type="text" id="perm_key_name" name="key_name" placeholder="my-app-key" required style="max-width:14rem;">
            </div>
            <div>
                <label for="perm_bucket">Bucket</label>
                <select id="perm_bucket" name="bucket" required style="max-width:14rem;">
                    @foreach ($buckets as $b)
                        <option value="{{ $b }}">{{ $b }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="form-row" style="margin-bottom:0.75rem;">
            <label style="display:flex; align-items:center; gap:0.3rem; cursor:pointer;">
                <input type="checkbox" name="read" value="1" checked> <span class="text-sm">Read</span>
            </label>
            <label style="display:flex; align-items:center; gap:0.3rem; cursor:pointer;">
                <input type="checkbox" name="write" value="1" checked> <span class="text-sm">Write</span>
            </label>
            <label style="display:flex; align-items:center; gap:0.3rem; cursor:pointer;">
                <input type="checkbox" name="owner" value="1" checked> <span class="text-sm">Owner</span>
            </label>
        </div>
        <div class="form-row">
            <button type="submit">Grant permissions</button>
            <button type="submit" class="danger" formaction="{{ route('keys.deny') }}">Revoke permissions</button>
        </div>
    </form>
</div>

<div class="card">
    <h2>Key list</h2>
    @if ($error)
        <div class="errors">{{ $error }}</div>
    @else
        <pre class="raw">{{ $output }}</pre>
    @endif
</div>
@endsection
