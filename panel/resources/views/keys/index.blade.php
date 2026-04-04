@extends('layouts.app')

@section('title', 'API keys — '.config('app.name'))

@section('content')
<h1>Garage API keys</h1>
<p class="muted">Keys are managed by the Garage CLI as the <code>johnny</code> user. The panel runs <code>sudo -u johnny johnny key list</code> — install sudoers (see Johnny README) for this to work.</p>

@if (session('status'))
    <div class="status">{{ session('status') }}</div>
@endif
@if (session('key_create_output'))
    <div class="card">
        <h2>New key output (copy secret now)</h2>
        <pre class="raw">{{ session('key_create_output') }}</pre>
    </div>
@endif
@if ($errors->any())
    <div class="errors">{{ $errors->first() }}</div>
@endif

<div class="card">
    <h2>Create key</h2>
    <form method="POST" action="{{ route('keys.store') }}" class="stack">
        @csrf
        <input type="text" name="name" value="{{ old('name') }}" placeholder="my-app-key" required>
        <button type="submit">Create key</button>
    </form>
</div>

<div class="card">
    <h2>Key list</h2>
    @if ($error)
        <p class="errors">{{ $error }}</p>
    @else
        <pre class="raw">{{ $output }}</pre>
    @endif
</div>
@endsection
