@extends('layouts.app')

@use('Illuminate\Support\Carbon')

@section('title', 'Updates — '.config('app.name'))

@section('content')
@php
    $busy = $running || $serverRunning;
    $newest = $channel === 'beta' ? ($state['main'] ?? null) : ($state['latest'] ?? null);
    $lastStatus = $state['status'] ?? null;
    $statusBadge = fn (?string $status) => match ($status) {
        'ok' => ['badge-success', 'updated'],
        'up-to-date' => ['badge-success', 'up to date'],
        'failed' => ['badge-danger', 'failed'],
        'running' => ['', 'running'],
        default => ['badge-muted', (string) $status],
    };
@endphp

<div class="page-header page-header-row">
    <div>
        <h1>Updates</h1>
        <p class="subtitle">The build of Johnny this server runs, its updates, and the server's own packages.</p>
    </div>
    @if ($managed)
        <div class="page-header-actions">
            @if (! empty($state['checked_at']))
                <span class="muted text-xs" title="{{ $state['checked_at'] }}">Checked {{ Carbon::parse($state['checked_at'])->diffForHumans() }}</span>
            @endif
            <form method="POST" action="{{ route('updates.check') }}" class="inline-form">
                @csrf
                <button type="submit" class="ghost sm" @disabled($busy)>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-2.64-6.36L21 8"/><path d="M21 3v5h-5"/></svg>
                    Check now
                </button>
            </form>
        </div>
    @endif
</div>

@if (session('status'))
    <div class="status">{{ session('status') }}</div>
@endif
@if ($errors->any())
    <div class="errors">{{ $errors->first() }}</div>
@endif

@unless ($managed)
    <div class="card">
        <h2>Johnny</h2>
        <div class="info-grid">
            <div>
                <div class="info-label">Installed</div>
                <div class="info-value"><span class="version">{{ $version }}</span></div>
            </div>
        </div>
        <p class="notice">
            Updates are managed by the installer on the server. This panel cannot reach the updater here
            (there is no <code>/var/lib/johnny-update/state.json</code>, and no sudo rule for <code>johnny update</code>):
            on a server set up by <code>scripts/autoinstall.sh</code>, update with <code>sudo johnny update apply</code>.
        </p>
    </div>
@else
    {{-- Johnny itself --}}
    <div class="card">
        <div class="card-head">
            <h2>Johnny</h2>
            <div class="stack">
                <form method="POST" action="{{ route('updates.start') }}" class="inline-form"
                      data-confirm="Force an update now? Johnny installs the newest build of its channel again, even when it is the one already installed (and also when its history differs), with the same database backup and rollback as every update. It never goes back to an older build.">
                    @csrf
                    <input type="hidden" name="force" value="1">
                    <button type="submit" class="ghost sm" @disabled($busy)>Force update</button>
                </form>
                @if ($available && ! $running)
                    <form method="POST" action="{{ route('updates.start') }}" class="inline-form"
                          data-confirm="Update Johnny to {{ $available }} now? It backs up the panel database, checks the new build out, runs its install steps and checks that everything answers; if a step fails, the previous build and its database come back. The panel is in maintenance for a moment.">
                        @csrf
                        <button type="submit" class="sm" @disabled($busy)>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="16 12 12 8 8 12"/><line x1="12" y1="16" x2="12" y2="8"/></svg>
                            Update to {{ $available }}
                        </button>
                    </form>
                @endif
            </div>
        </div>

        <div class="info-grid">
            <div>
                <div class="info-label">Installed</div>
                <div class="info-value"><span class="version">{{ $version }}</span></div>
                <div class="info-sub">
                    @if (! empty($state['current']))
                        build <a href="{{ $releaseUrl($state['current']) }}" target="_blank" rel="noopener">{{ $state['current'] }}</a>
                    @endif
                    @if (! empty($state['previous']) && $state['previous'] !== ($state['current'] ?? null))
                        · before: {{ $state['previous'] }}
                    @endif
                </div>
            </div>
            <div>
                <div class="info-label">{{ $channel === 'beta' ? 'Newest on main' : 'Newest release' }}</div>
                <div class="info-value">
                    @if (! empty($newest))
                        <a href="{{ $releaseUrl($newest) }}" target="_blank" rel="noopener">{{ $newest }}</a>
                    @else
                        <span class="muted">—</span>
                    @endif
                    @if ($running)
                        <span class="badge">updating…</span>
                    @elseif ($available)
                        <span class="badge badge-warn">update available</span>
                    @elseif (! empty($newest) && ! $waiting)
                        <span class="badge badge-success">up to date</span>
                    @endif
                </div>
                <div class="info-sub">{{ $channel === 'beta' ? 'beta channel' : 'stable channel' }}</div>
            </div>
            <div>
                <div class="info-label">Automatic updates</div>
                <div class="info-value">
                    <span @class(['badge', 'badge-success' => $automatic, 'badge-muted' => ! $automatic])>{{ $automatic ? 'on' : 'off' }}</span>
                </div>
                <div class="info-sub">{{ $automatic ? 'every night at 02:30, to the newest build' : 'only when started here or on the server' }}</div>
            </div>
        </div>

        @if ($waiting && ! $running)
            <p class="notice">{{ $waiting }}</p>
        @endif

        {{-- An update under way, followed by the script below (also through the panel's maintenance) --}}
        <div id="update-live" data-status-url="{{ route('updates.status') }}" @if (! $running) hidden @endif>
            <div class="run-head">
                <span class="badge"><span class="spinner" aria-hidden="true"></span> running</span>
                <span data-live-message>{{ $state['message'] ?? 'The update is starting…' }}</span>
            </div>
            <pre class="raw" data-live-log>{{ implode("\n", (array) ($state['log'] ?? [])) }}</pre>
            <p class="muted text-xs">This page follows the update and reloads itself when it is done.</p>
        </div>

        @if (! $running && $lastStatus)
            @php([$badgeClass, $badgeLabel] = $statusBadge($lastStatus))
            <div class="run-head">
                <span class="badge {{ $badgeClass }}">{{ $badgeLabel }}</span>
                <span>{{ $state['message'] ?? '' }}</span>
                @if (! empty($state['finished_at']))
                    <span class="muted text-xs" title="{{ $state['finished_at'] }}">
                        {{ Carbon::parse($state['finished_at'])->diffForHumans() }}{{ ($state['triggered_automatically'] ?? false) === true ? ' · nightly' : '' }}
                    </span>
                @endif
            </div>
            @if (! empty($state['log']))
                <details class="log" @if ($lastStatus === 'failed') open @endif>
                    <summary>Log of the last update</summary>
                    <pre class="raw">{{ implode("\n", (array) $state['log']) }}</pre>
                </details>
            @endif
            @if ($lastStatus === 'failed')
                <p class="muted text-xs">When a step after the checkout fails, the previous build and its panel database are put back. The full log is in <code>/var/log/johnny-update.log</code> on the server.</p>
            @endif
        @endif

        <div class="setting-row">
            <div>
                <h3>Channel</h3>
                <p class="muted text-sm">
                    @if ($channel === 'beta')
                        Beta: Johnny follows the main branch and installs every new commit on it, before it becomes a release. For a server you test on.
                    @else
                        Stable: Johnny installs the release tags (X.Y.Z) and nothing in between.
                    @endif
                    Neither channel goes backwards: back on stable after beta, Johnny keeps its main build until a release contains it.
                </p>
            </div>
            <form method="POST" action="{{ route('updates.channel') }}" class="stack">
                @csrf
                <button type="submit" name="channel" value="stable" @class(['sm', 'ghost' => $channel !== 'stable']) aria-pressed="{{ $channel === 'stable' ? 'true' : 'false' }}" @disabled($busy)>Stable releases</button>
                <button type="submit" name="channel" value="beta" @class(['sm', 'ghost' => $channel !== 'beta']) aria-pressed="{{ $channel === 'beta' ? 'true' : 'false' }}" @disabled($busy)>Beta (main branch)</button>
            </form>
        </div>

        <div class="setting-row">
            <div>
                <h3>Automatic updates</h3>
                <p class="muted text-sm">Every night at 02:30, Johnny installs the newest build of its channel when there is one. Off, it still checks; updates happen when started here or with <code>sudo johnny update apply</code>.</p>
            </div>
            <form method="POST" action="{{ route('updates.auto') }}" class="inline-form">
                @csrf
                <input type="hidden" name="enabled" value="{{ $automatic ? 0 : 1 }}">
                <button type="submit" class="ghost sm">{{ $automatic ? 'Turn off' : 'Turn on' }}</button>
            </form>
        </div>
    </div>

    {{-- The server itself --}}
    <div class="card">
        <div class="card-head">
            <h2>Server</h2>
            <form method="POST" action="{{ route('updates.system.start') }}" class="inline-form"
                  data-confirm="Update every package of the server to its newest version now? Ubuntu and its security fixes, PHP, Caddy, Composer; the services whose packages changed restart for a moment.">
                @csrf
                <button type="submit" class="ghost sm" @disabled($busy)>Update the server now</button>
            </form>
        </div>

        <div class="info-grid">
            <div>
                <div class="info-label">Last server update</div>
                <div class="info-value">
                    @if ($serverRunning)
                        <span class="badge">updating…</span>
                    @elseif (! empty($server['finished_at']))
                        @php([$badgeClass, $badgeLabel] = $statusBadge($server['status'] ?? null))
                        <span title="{{ $server['finished_at'] }}">{{ Carbon::parse($server['finished_at'])->diffForHumans() }}</span>
                        <span class="badge {{ $badgeClass }}">{{ $badgeLabel }}</span>
                    @else
                        <span class="muted">not yet</span>
                    @endif
                </div>
                @if (! empty($server['message']) && ! $serverRunning)
                    <div class="info-sub">{{ $server['message'] }}</div>
                @endif
            </div>
            <div>
                <div class="info-label">Reboot</div>
                <div class="info-value">
                    @if (($server['reboot_required'] ?? false) === true)
                        <span class="badge badge-warn">reboot required</span>
                    @else
                        <span class="muted">not needed</span>
                    @endif
                </div>
                @if (($server['reboot_required'] ?? false) === true)
                    <div class="info-sub">
                        A new kernel or system library runs from the next reboot{{ ! empty($server['reboot_packages']) ? ' ('.implode(', ', (array) $server['reboot_packages']).')' : '' }}.
                    </div>
                @endif
            </div>
            <div>
                <div class="info-label">Nightly server updates</div>
                <div class="info-value">
                    <span @class(['badge', 'badge-success' => $serverAutomatic, 'badge-muted' => ! $serverAutomatic])>{{ $serverAutomatic ? 'on' : 'off' }}</span>
                </div>
                <div class="info-sub">{{ $serverAutomatic ? 'every night at 02:00, every package' : 'only when started here or on the server' }}</div>
            </div>
        </div>

        <div id="system-live" @if (! $serverRunning) hidden @endif>
            <div class="run-head">
                <span class="badge"><span class="spinner" aria-hidden="true"></span> running</span>
                <span data-live-message>{{ $server['message'] ?? 'The server update is starting…' }}</span>
            </div>
            <pre class="raw" data-live-log>{{ implode("\n", (array) ($server['log'] ?? [])) }}</pre>
        </div>

        @if (! $serverRunning && ! empty($server['changes']))
            <details class="log">
                <summary>{{ count((array) $server['changes']) }} changed in the last server update</summary>
                <pre class="raw">{{ implode("\n", (array) $server['changes']) }}</pre>
            </details>
        @endif
        @if (! $serverRunning && ! empty($server['log']))
            <details class="log" @if (($server['status'] ?? null) === 'failed') open @endif>
                <summary>Log of the last server update</summary>
                <pre class="raw">{{ implode("\n", (array) $server['log']) }}</pre>
            </details>
        @endif

        <div class="setting-row">
            <div>
                <h3>Nightly server updates</h3>
                <p class="muted text-sm">
                    Every night at 02:00, half an hour before Johnny's own update: <code>apt-get full-upgrade</code> (old configuration files are kept),
                    Composer and rclone when they were not installed from apt, then PHP-FPM, Caddy and Garage restart if their packages changed.
                    A reboot stays a decision for you, unless <code>SYSTEM_REBOOT=1</code> is set in <code>/etc/johnny/update.env</code>.
                </p>
            </div>
            <form method="POST" action="{{ route('updates.system.auto') }}" class="inline-form">
                @csrf
                <input type="hidden" name="enabled" value="{{ $serverAutomatic ? 0 : 1 }}">
                <button type="submit" class="ghost sm">{{ $serverAutomatic ? 'Turn off' : 'Turn on' }}</button>
            </form>
        </div>
    </div>
@endunless
@endsection

@if ($managed && $busy)
@push('scripts')
<script>
// Follows a running update: its message and log, every few seconds. While the panel is in
// maintenance (the switch to the new build) the status answers 503: keep waiting. Once
// nothing runs any more, reload the page onto the result.
(function () {
    var johnny = document.getElementById('update-live');
    var server = document.getElementById('system-live');
    if (!johnny || !server) return;
    var url = johnny.getAttribute('data-status-url');

    function show(box, part) {
        box.hidden = !part.running;
        if (part.message) box.querySelector('[data-live-message]').textContent = part.message;
        var log = box.querySelector('[data-live-log]');
        log.textContent = (part.log || []).join('\n');
        log.scrollTop = log.scrollHeight;
    }

    function poll() {
        fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin', cache: 'no-store' })
            .then(function (response) {
                if (response.status === 503) {
                    johnny.querySelector('[data-live-message]').textContent = 'Johnny is switching to the new build…';
                    return null;
                }
                return response.ok ? response.json() : null;
            })
            .then(function (status) {
                if (!status) return;
                if (!status.running && !status.system.running) {
                    window.location.reload();
                    return;
                }
                show(johnny, status);
                show(server, status.system);
            })
            .catch(function () {})
            .finally(function () { setTimeout(poll, 3000); });
    }

    setTimeout(poll, 2000);
})();
</script>
@endpush
@endif
