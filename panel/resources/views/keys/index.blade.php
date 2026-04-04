@extends('layouts.app')

@section('title', 'API Keys — '.config('app.name'))

@section('content')
<div class="page-header">
    <h1>API Keys</h1>
    <p class="subtitle">Create and delete Garage API keys. To grant bucket access, open the bucket and use the Keys tab.</p>
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
    <form method="POST" action="{{ route('keys.store') }}" class="form-row">
        @csrf
        <input type="text" name="name" value="{{ old('name') }}" placeholder="my-app-key" required>
        <button type="submit">Create key</button>
    </form>
</div>

<div class="card">
    <h2>Delete key</h2>
    <p class="muted text-sm" style="margin-bottom:0.75rem;">This permanently removes the key and all its bucket permissions.</p>
    <form method="POST" action="{{ route('keys.destroy') }}" class="form-row" onsubmit="return confirm('Permanently delete this key?');">
        @csrf
        @method('DELETE')
        <input type="text" name="key_name" placeholder="key-name" required>
        <button type="submit" class="danger">Delete key</button>
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
