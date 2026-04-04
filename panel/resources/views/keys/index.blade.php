@extends('layouts.app')

@section('title', 'API Keys — '.config('app.name'))

@section('content')
<div class="page-header">
    <h1>API Keys</h1>
    <p class="subtitle">Keys are managed via the Garage CLI. The panel runs <code>sudo -u johnny johnny key list</code>.</p>
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
    <h2>Key list</h2>
    @if ($error)
        <div class="errors">{{ $error }}</div>
    @else
        <pre class="raw">{{ $output }}</pre>
    @endif
</div>
@endsection
