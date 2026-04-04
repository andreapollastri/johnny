@extends('layouts.app')

@section('title', 'Security — '.config('app.name'))

@section('content')
@php($user = auth()->user())
<h1>Security</h1>
<p class="muted">Two-factor authentication uses Laravel Fortify (TOTP). Confirm your account password when prompted.</p>

<div class="card">
    <h2>Two-factor authentication</h2>
    @if ($user->hasEnabledTwoFactorAuthentication())
        <p class="status">2FA is enabled.</p>
        <p><img src="{{ url('/user/two-factor-qr-code') }}" alt="QR" style="max-width:200px;background:#fff;padding:0.5rem;border-radius:4px;"></p>
        <form method="POST" action="{{ url('/user/two-factor-authentication') }}">
            @csrf
            @method('DELETE')
            <button type="submit" class="danger">Disable 2FA</button>
        </form>
        <p class="muted" style="margin-top:1rem;"><a href="{{ url('/user/two-factor-recovery-codes') }}" target="_blank" rel="noopener">Recovery codes</a> (JSON).</p>
    @elseif ($user->two_factor_secret)
        <p class="muted">Scan the QR code with your authenticator app, then enter the code to confirm.</p>
        <p><img src="{{ url('/user/two-factor-qr-code') }}" alt="QR" style="max-width:200px;background:#fff;padding:0.5rem;border-radius:4px;"></p>
        <form method="POST" action="{{ url('/user/confirmed-two-factor-authentication') }}">
            @csrf
            <label for="code">Authentication code</label>
            <input id="code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" required>
            <div style="margin-top:0.75rem;">
                <button type="submit">Confirm and enable</button>
            </div>
        </form>
    @else
        <form method="POST" action="{{ url('/user/two-factor-authentication') }}">
            @csrf
            <button type="submit">Enable 2FA (generate secret)</button>
        </form>
    @endif
</div>
@endsection
