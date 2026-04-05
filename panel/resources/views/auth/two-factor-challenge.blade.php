@extends('layouts.app')

@section('title', 'Two-factor — '.config('app.name'))

@section('content')
<div class="auth-card">
    <div class="brand">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
        {{ config('app.name') }}
    </div>
    <h1>Two-factor authentication</h1>
    <p class="subtitle">Use your authenticator app or a one-time recovery code.</p>

    @if ($errors->any())
        <div class="errors">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('two-factor.login.store') }}" id="two-factor-form"
        data-initial-recovery="{{ filled(old('recovery_code')) ? '1' : '0' }}">
        @csrf
        <div class="two-factor-toggle" role="group" aria-label="Verification method">
            <button type="button" class="is-active" id="two-factor-mode-app" aria-pressed="true">Authenticator app</button>
            <button type="button" id="two-factor-mode-recovery" aria-pressed="false">Recovery code</button>
        </div>
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
            <p class="muted text-sm" id="two-factor-token-hint" style="margin-top:0.35rem;">6-digit code from your app.</p>
        </div>
        <button type="submit">Continue</button>
    </form>
    <script>
    (function () {
        var form = document.getElementById('two-factor-form');
        var input = document.getElementById('two-factor-token');
        var label = document.getElementById('two-factor-token-label');
        var hint = document.getElementById('two-factor-token-hint');
        var btnApp = document.getElementById('two-factor-mode-app');
        var btnRec = document.getElementById('two-factor-mode-recovery');
        if (!form || !input || !label || !hint || !btnApp || !btnRec) return;

        function setMode(recovery) {
            input.value = '';
            if (recovery) {
                input.name = 'recovery_code';
                input.removeAttribute('inputmode');
                input.setAttribute('autocomplete', 'off');
                input.setAttribute('spellcheck', 'false');
                label.textContent = 'Recovery code';
                hint.textContent = 'One of your saved recovery codes.';
                btnApp.classList.remove('is-active');
                btnApp.setAttribute('aria-pressed', 'false');
                btnRec.classList.add('is-active');
                btnRec.setAttribute('aria-pressed', 'true');
            } else {
                input.name = 'code';
                input.setAttribute('inputmode', 'numeric');
                input.setAttribute('autocomplete', 'one-time-code');
                input.removeAttribute('spellcheck');
                label.textContent = 'Authentication code';
                hint.textContent = '6-digit code from your app.';
                btnRec.classList.remove('is-active');
                btnRec.setAttribute('aria-pressed', 'false');
                btnApp.classList.add('is-active');
                btnApp.setAttribute('aria-pressed', 'true');
            }
            input.focus();
        }

        btnApp.addEventListener('click', function () { setMode(false); });
        btnRec.addEventListener('click', function () { setMode(true); });

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
