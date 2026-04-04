@extends('layouts.app')

@section('title', 'Confirm password — '.config('app.name'))

@section('content')
<div class="auth-card">
    <div class="brand">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
        {{ config('app.name') }}
    </div>
    <h1>Confirm password</h1>
    <p class="subtitle">This area requires your current password to continue.</p>

    @if ($errors->any())
        <div class="errors">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('password.confirm.store') }}">
        @csrf
        <div class="form-group">
            <label for="password">Password</label>
            <input id="password" type="password" name="password" required autocomplete="current-password" autofocus>
        </div>
        <button type="submit">Confirm</button>
    </form>
</div>
@endsection
