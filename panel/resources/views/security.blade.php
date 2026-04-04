@extends('layouts.app')

@section('title', 'Security — '.config('app.name'))

@section('content')
@php($user = auth()->user())

<div class="page-header">
    <h1>Security</h1>
    <p class="subtitle">Manage two-factor authentication for your account.</p>
</div>

<div class="card">
    <h2>Two-factor authentication</h2>

    @if ($user->hasEnabledTwoFactorAuthentication())
        <div class="status">2FA is enabled.</div>
        <div class="qr-frame mb-1">
            <img src="{{ url('/user/two-factor-qr-code') }}" alt="QR Code">
        </div>
        <form method="POST" action="{{ url('/user/two-factor-authentication') }}" class="mt-2">
            @csrf
            @method('DELETE')
            <button type="submit" class="danger">Disable 2FA</button>
        </form>
        <p class="muted mt-2"><a href="{{ url('/user/two-factor-recovery-codes') }}" target="_blank" rel="noopener">View recovery codes</a></p>

    @elseif ($user->two_factor_secret)
        <p class="muted">Scan the QR code with your authenticator app, then enter the code to confirm.</p>
        <div class="qr-frame mb-1">
            <img src="{{ url('/user/two-factor-qr-code') }}" alt="QR Code">
        </div>
        <form method="POST" action="{{ url('/user/confirmed-two-factor-authentication') }}" class="mt-2">
            @csrf
            <div class="form-group">
                <label for="code">Authentication code</label>
                <input id="code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" required>
            </div>
            <button type="submit">Confirm and enable</button>
        </form>

    @else
        <p class="muted">Protect your account with time-based one-time passwords (TOTP).</p>
        <form method="POST" action="{{ url('/user/two-factor-authentication') }}" class="mt-2">
            @csrf
            <button type="submit">Enable 2FA</button>
        </form>
    @endif
</div>
@endsection
