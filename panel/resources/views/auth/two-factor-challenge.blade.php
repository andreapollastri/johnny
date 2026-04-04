@extends('layouts.app')

@section('title', 'Two-factor — '.config('app.name'))

@section('content')
<div class="auth-card">
    <div class="brand">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
        {{ config('app.name') }}
    </div>
    <h1>Two-factor authentication</h1>
    <p class="subtitle">Enter the code from your authenticator app, or use a recovery code.</p>

    @if ($errors->any())
        <div class="errors">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('two-factor.login.store') }}">
        @csrf
        <div class="form-group">
            <label for="code">Authentication code</label>
            <input id="code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" autofocus>
        </div>
        <div class="form-group">
            <label for="recovery_code">Recovery code</label>
            <input id="recovery_code" type="text" name="recovery_code" autocomplete="off">
        </div>
        <button type="submit">Continue</button>
    </form>
</div>
@endsection
