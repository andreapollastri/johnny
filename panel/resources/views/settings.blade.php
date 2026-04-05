@extends('layouts.app')

@section('title', 'Settings — '.config('app.name'))

@section('content')
@php($user = auth()->user())

<div class="page-header">
    <h1>Settings</h1>
    <p class="subtitle">Manage two-factor authentication and panel API tokens. (Check the <a target="_blank" href="{{ route('api.docs') }}">API docs</a> for more information).</p>
</div>

@if ($flashStatus = session('status'))
    <div class="status">{{ match ($flashStatus) {
        'recovery-codes-generated' => 'Recovery codes have been regenerated. Save the new codes — the old ones no longer work.',
        'two-factor-authentication-disabled' => 'Two-factor authentication has been disabled.',
        'two-factor-authentication-enabled' => 'Two-factor authentication setup started. Confirm with your app to finish.',
        'two-factor-authentication-confirmed' => 'Two-factor authentication is now enabled.',
        default => $flashStatus,
    } }}</div>
@endif
@if ($errors->any())
    <div class="errors">{{ $errors->first() }}</div>
@endif

@if (session('sanctum_token_plain'))
<div class="card">
    <h2>New API token</h2>
    <p class="muted text-sm">Copy this token now — it will not be shown again. Use it as <code class="text-xs">Authorization: Bearer …</code></p>
    <pre class="raw" style="word-break:break-all;">{{ session('sanctum_token_plain') }}</pre>
</div>
@endif

<div class="card">
    <h2>Two-factor authentication</h2>

    @if ($user->hasEnabledTwoFactorAuthentication())
        <p class="muted" style="margin-bottom:0.75rem;">Two-factor authentication is enabled.</p>
        <form method="POST" action="{{ url('/user/two-factor-authentication') }}" class="mt-2">
            @csrf
            @method('DELETE')
            <button type="submit" class="danger">Disable 2FA</button>
        </form>

        @if ($recoveryCodes !== null)
            <div class="recovery-codes-block" style="margin-top:1.25rem;">
                <h3 class="recovery-codes-heading">New recovery codes</h3>
                <p class="muted text-sm" style="margin-bottom:0.75rem;">Each code works once if you lose access to your authenticator. Save them now — they will not be shown again on this page.</p>
                @if (count($recoveryCodes) > 0)
                    <div class="recovery-codes-grid" id="recovery-codes-grid" role="list" aria-label="Two-factor recovery codes">
                        @foreach ($recoveryCodes as $code)
                            <div class="recovery-code-cell" role="listitem"><code>{{ $code }}</code></div>
                        @endforeach
                    </div>
                    <div class="recovery-codes-actions">
                        <button type="button" class="ghost sm" id="recovery-codes-copy-btn" onclick="copyRecoveryCodes()">Copy all</button>
                        <form method="POST" action="{{ url('/user/two-factor-recovery-codes') }}" style="margin:0; display:inline;" onsubmit="return confirm('Regenerate all recovery codes? The current codes will stop working immediately.');">
                            @csrf
                            <button type="submit" class="ghost sm">Regenerate codes</button>
                        </form>
                    </div>
                    <pre id="recovery-codes-plain" class="recovery-codes-plain">{{ implode("\n", $recoveryCodes) }}</pre>
                @else
                    <p class="muted text-sm">No recovery codes left. Generate a new set.</p>
                    <form method="POST" action="{{ url('/user/two-factor-recovery-codes') }}" style="margin:0;" onsubmit="return confirm('Generate new recovery codes?');">
                        @csrf
                        <button type="submit" class="ghost sm">Generate recovery codes</button>
                    </form>
                @endif
            </div>
            <script>
            function copyRecoveryCodes() {
                var pre = document.getElementById('recovery-codes-plain');
                var btn = document.getElementById('recovery-codes-copy-btn');
                if (!pre || !navigator.clipboard) return;
                navigator.clipboard.writeText(pre.textContent.trim()).then(function() {
                    if (btn) {
                        var t = btn.textContent;
                        btn.textContent = 'Copied';
                        setTimeout(function() { btn.textContent = t; }, 2000);
                    }
                });
            }
            </script>
        @else
            <div style="margin-top:1.25rem;">
                <p class="muted text-sm" style="margin-bottom:0.75rem;">Recovery codes are not displayed here after setup. Regenerate to get a new set (you will see them once after each regeneration).</p>
                @if ($recoveryCodesExhausted)
                    <form method="POST" action="{{ url('/user/two-factor-recovery-codes') }}" style="margin:0;" onsubmit="return confirm('Generate new recovery codes?');">
                        @csrf
                        <button type="submit" class="ghost sm">Generate recovery codes</button>
                    </form>
                @else
                    <form method="POST" action="{{ url('/user/two-factor-recovery-codes') }}" style="margin:0;" onsubmit="return confirm('Regenerate all recovery codes? The current codes will stop working immediately.');">
                        @csrf
                        <button type="submit" class="ghost sm">Regenerate recovery codes</button>
                    </form>
                @endif
            </div>
        @endif

    @elseif ($user->two_factor_secret)
        <p class="muted">Scan the QR code with your authenticator app, then enter the code to confirm.</p>
        <div class="qr-frame mb-1" role="img" aria-label="Two-factor authentication QR code">
            {!! $user->twoFactorQrCodeSvg() !!}
        </div>

        @if ($twoFactorManualSecret)
            <p class="muted text-sm" style="margin-top:0.75rem;">If you cannot scan the code, add an account manually in your app (time-based / TOTP) and use this secret:</p>
            <pre class="raw" style="word-break:break-all; margin-bottom:0.75rem;">{{ implode(' ', str_split($twoFactorManualSecret, 4)) }}</pre>
        @endif

        <form method="POST" action="{{ url('/user/confirmed-two-factor-authentication') }}" class="mt-2">
            @csrf
            <div class="form-group">
                <label for="code">Authentication code</label>
                <input id="code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" required>
            </div>
            <button type="submit">Confirm and enable</button>
        </form>
        <form method="POST" action="{{ url('/user/two-factor-authentication') }}" class="mt-1" onsubmit="return confirm('Cancel two-factor setup? You can start again later.');">
            @csrf
            @method('DELETE')
            <button type="submit" class="ghost">Cancel setup</button>
        </form>

    @else
        <p class="muted">Protect your account with time-based one-time passwords (TOTP).</p>
        <form method="POST" action="{{ url('/user/two-factor-authentication') }}" class="mt-2">
            @csrf
            <button type="submit">Enable 2FA</button>
        </form>
    @endif
</div>

<div class="card">
    <h2>Panel API tokens</h2>
    <p class="muted text-sm" style="margin-bottom:0.75rem;">Tokens authenticate HTTP requests to the panel API (Sanctum). Revoke a token if it may be compromised.</p>

    <form method="POST" action="{{ route('settings.tokens.store') }}" class="form-row">
        @csrf
        <input type="text" name="name" value="{{ old('name') }}" placeholder="Label (e.g. CI, backup script)" required maxlength="255">
        <button type="submit">Create token</button>
    </form>
</div>

@if ($tokens->isNotEmpty())
<div class="card">
    <h2>Active tokens</h2>
    <table>
        <thead>
            <tr>
                <th>Name</th>
                <th>Last used</th>
                <th>Created</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        @foreach ($tokens as $t)
            <tr>
                <td>{{ $t->name }}</td>
                <td class="muted text-sm">{{ $t->last_used_at ? $t->last_used_at->diffForHumans() : '—' }}</td>
                <td class="muted text-sm">{{ $t->created_at->format('Y-m-d H:i') }}</td>
                <td>
                    <form method="POST" action="{{ route('settings.tokens.destroy', $t->id) }}" onsubmit="return confirm('Revoke this token? Apps using it will stop working.');" style="margin:0;">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="danger sm">Revoke</button>
                    </form>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@else
<div class="card">
    <div class="empty-state">
        <p>No API tokens yet. Create one above to call authenticated panel endpoints.</p>
    </div>
</div>
@endif
@endsection
