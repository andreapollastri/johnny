@extends('layouts.app')

@section('title', 'Two-factor — '.config('app.name'))

@section('content')
<div class="auth-card">
    <div class="brand">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
        {{ config('app.name') }}
    </div>
    <h1>Two-factor authentication</h1>
    <p class="subtitle">Almost there — confirm with your app or a backup code.</p>

    @if ($errors->any())
        <div class="errors">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('two-factor.login.store') }}" id="two-factor-form"
        data-initial-recovery="{{ filled(old('recovery_code')) ? '1' : '0' }}">
        @csrf
        <div class="form-group">
            <label for="two-factor-token" id="two-factor-token-label">Authentication code</label>
            <input
                id="two-factor-token"
                name="code"
                type="text"
                inputmode="numeric"
                autocomplete="one-time-code"
                required
                autofocus
                aria-describedby="two-factor-token-hint"
            >
            <p class="muted text-sm" id="two-factor-token-hint" style="margin-top:0.35rem;">6-digit code from your authenticator app.</p>
            <p class="two-factor-flip-wrap">
                <button type="button" class="two-factor-flip" id="two-factor-flip" aria-label="Switch to recovery code">
                    Use a recovery code instead
                </button>
            </p>
        </div>
        <button type="submit">Continue</button>
    </form>
    <script>
    (function () {
        var form = document.getElementById('two-factor-form');
        var input = document.getElementById('two-factor-token');
        var label = document.getElementById('two-factor-token-label');
        var hint = document.getElementById('two-factor-token-hint');
        var flip = document.getElementById('two-factor-flip');
        if (!form || !input || !label || !hint || !flip) return;

        var txtToRecovery = 'Use a recovery code instead';
        var txtToApp = 'Use authenticator app instead';

        function setMode(recovery) {
            input.value = '';
            if (recovery) {
                input.name = 'recovery_code';
                input.removeAttribute('inputmode');
                input.setAttribute('autocomplete', 'off');
                input.setAttribute('spellcheck', 'false');
                label.textContent = 'Recovery code';
                hint.textContent = 'Enter one of the one-time codes you saved when you enabled 2FA.';
                flip.textContent = txtToApp;
                flip.setAttribute('aria-label', 'Switch to authenticator app code');
            } else {
                input.name = 'code';
                input.setAttribute('inputmode', 'numeric');
                input.setAttribute('autocomplete', 'one-time-code');
                input.removeAttribute('spellcheck');
                label.textContent = 'Authentication code';
                hint.textContent = '6-digit code from your authenticator app.';
                flip.textContent = txtToRecovery;
                flip.setAttribute('aria-label', 'Switch to recovery code');
            }
            input.focus();
        }

        flip.addEventListener('click', function () {
            var recovery = input.name !== 'recovery_code';
            setMode(recovery);
        });

        if (form.getAttribute('data-initial-recovery') === '1') {
            setMode(true);
            input.value = @json(old('recovery_code', ''));
        } else {
            input.value = @json(old('code', ''));
        }
    })();
    </script>
</div>
@endsection
