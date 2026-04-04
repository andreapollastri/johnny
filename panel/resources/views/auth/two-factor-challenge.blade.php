@extends('layouts.app')

@section('title', 'Two-factor — '.config('app.name'))

@section('content')
<div class="login-box card">
    <h1>Two-factor authentication</h1>
    <p class="muted">Enter the code from your authenticator app or a recovery code.</p>
    @if ($errors->any())
        <div class="errors">{{ $errors->first() }}</div>
    @endif
    <form method="POST" action="{{ route('two-factor.login.store') }}">
        @csrf
        <label for="code">Authentication code</label>
        <input id="code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" autofocus>
        <label for="recovery_code" style="margin-top:0.75rem;">Recovery code</label>
        <input id="recovery_code" type="text" name="recovery_code" autocomplete="off">
        <div style="margin-top:1rem;">
            <button type="submit">Continue</button>
        </div>
    </form>
</div>
@endsection
