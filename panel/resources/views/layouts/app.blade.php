<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', config('app.name'))</title>
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%2318181b' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z'/%3E%3Cpolyline points='3.27 6.96 12 12.01 20.73 6.96'/%3E%3Cline x1='12' y1='22.08' x2='12' y2='12'/%3E%3C/svg%3E">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
    <script>
    (function(){
        try {
            var t = localStorage.getItem('theme');
            if (t === 'dark' || t === 'light') document.documentElement.setAttribute('data-theme', t);
        } catch (e) {}
    })();
    </script>
</head>
<body>
@auth
@php
    $updateAvailable = app(\App\Services\JohnnyUpdates::class)->available();
    $activeTransfers = \App\Support\Nav::activeTransfers();
    $paletteItems = [
        ['label' => 'Dashboard', 'kind' => 'Page', 'url' => route('dashboard')],
        ['label' => 'Buckets', 'kind' => 'Page', 'url' => route('buckets.index')],
        ['label' => 'Access keys', 'kind' => 'Page', 'url' => route('keys.index')],
        ['label' => 'Transfers', 'kind' => 'Page', 'url' => route('transfers.index')],
        ['label' => 'Updates', 'kind' => 'Page', 'url' => route('updates.show')],
        ['label' => 'Settings', 'kind' => 'Page', 'url' => route('settings.show')],
        ['label' => 'API docs', 'kind' => 'Page', 'url' => route('api.docs')],
        ['label' => 'Create a bucket', 'kind' => 'Action', 'url' => route('buckets.index').'#create'],
        ['label' => 'Create an access key', 'kind' => 'Action', 'url' => route('keys.index').'#create'],
        ['label' => 'Import or export a bucket', 'kind' => 'Action', 'url' => route('transfers.create')],
        ['label' => 'Switch between light and dark', 'kind' => 'Action', 'action' => 'theme'],
    ];
@endphp
<div class="app" id="app">
    <header class="bar">
        <div class="bar-inner">
            <button type="button" class="icon-btn bar-menu" data-nav-toggle aria-label="Menu" aria-controls="nav" aria-expanded="false">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
            </button>
            <a href="{{ route('dashboard') }}" class="brand" aria-label="{{ config('app.name') }}">
                <span class="brand-mark"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg></span>
                <span>Johnny</span>
            </a>

            <nav class="nav" id="nav" aria-label="Main">
                <a href="{{ route('dashboard') }}" @class(['nav-link', 'is-active' => request()->routeIs('dashboard')])>Dashboard</a>
                <a href="{{ route('buckets.index') }}" @class(['nav-link', 'is-active' => request()->routeIs('buckets.*') || request()->routeIs('objects.*')])>Buckets</a>
                <a href="{{ route('keys.index') }}" @class(['nav-link', 'is-active' => request()->routeIs('keys.*')])>Access keys</a>
                <a href="{{ route('transfers.index') }}" @class(['nav-link', 'is-active' => request()->routeIs('transfers.*') || request()->routeIs('connections.*') || request()->routeIs('syncs.*')])>
                    Transfers
                    @if ($activeTransfers > 0)
                        <span class="nav-count" title="{{ $activeTransfers }} running or waiting">{{ $activeTransfers }}</span>
                    @endif
                </a>
            </nav>

            <div class="bar-tools">
                <button type="button" class="jump" data-palette-open aria-label="Jump to a page or a bucket">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <span>Jump to…</span>
                    <kbd>Ctrl K</kbd>
                </button>
                <a href="{{ route('updates.show') }}" @class(['icon-btn', 'is-active' => request()->routeIs('updates.*')]) title="{{ $updateAvailable ? 'Updates — '.$updateAvailable.' is available' : 'Updates' }}" aria-label="Updates">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><polyline points="16 12 12 8 8 12"/><line x1="12" y1="16" x2="12" y2="8"/></svg>
                    @if ($updateAvailable)
                        <span class="nav-dot"></span>
                    @endif
                </a>
                <button type="button" class="icon-btn" data-theme-toggle title="Switch between light and dark" aria-label="Switch between light and dark">
                    <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
                <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
                </button>
                <details class="menu">
                    <summary class="avatar" aria-label="Account" title="{{ auth()->user()->email }}">{{ substr(auth()->user()->email, 0, 1) }}</summary>
                    <div class="menu-panel">
                        <div class="menu-label">{{ auth()->user()->email }}</div>
                        <a href="{{ route('settings.show') }}" class="menu-item"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg> Settings</a>
                        <a href="{{ route('updates.show') }}" class="menu-item"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><polyline points="16 12 12 8 8 12"/><line x1="12" y1="16" x2="12" y2="8"/></svg> Updates</a>
                        <a href="{{ route('api.docs') }}" class="menu-item" target="_blank" rel="noopener"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg> API docs</a>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="menu-item"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg> Log out</button>
                        </form>
                    </div>
                </details>
            </div>
        </div>
    </header>

    <main class="wrap">
        <nav class="crumbs" aria-label="Breadcrumb">
            @hasSection('crumbs')
                @yield('crumbs')
            @endif
        </nav>

        @hasSection('flash')
            @yield('flash')
        @else
            @include('partials.flash')
        @endif

        @yield('content')
    </main>

    <div class="scrim" id="nav-scrim" hidden></div>
</div>

<dialog id="confirm-dialog" aria-labelledby="confirm-title">
    <div class="dialog-body">
        <h2 class="dialog-title" id="confirm-title" data-confirm-title>Confirm</h2>
        <p data-confirm-message></p>
    </div>
    <div class="dialog-actions">
        <button type="button" class="btn" data-dialog-close>Cancel</button>
        <button type="button" class="btn btn-primary" data-confirm-ok>Confirm</button>
    </div>
</dialog>

<dialog class="palette" id="palette" aria-label="Jump to" data-buckets-url="{{ route('buckets.index') }}" data-bucket-url="{{ route('buckets.show', '__bucket__') }}">
    <input type="text" class="palette-input" placeholder="Jump to a page or a bucket…" aria-label="Search pages and buckets" autocomplete="off" spellcheck="false">
    <ul class="palette-list" role="listbox"></ul>
    <div class="palette-foot"><span><kbd>↑</kbd> <kbd>↓</kbd> to move</span><span><kbd>Enter</kbd> to open</span><span><kbd>Esc</kbd> to close</span></div>
</dialog>
<script type="application/json" id="palette-data">@json($paletteItems)</script>

<div class="toasts" id="toasts" aria-live="polite"></div>
@else
<main class="auth-wrapper">
    <div class="auth-main">
        <span class="brand auth-brand">
            <span class="brand-mark"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg></span>
            <span>Johnny</span>
        </span>
        @yield('content')
        <p class="auth-foot">Your storage. Your rules.</p>
    </div>
</main>
@endauth

<script src="{{ asset('js/app.js') }}?v={{ filemtime(public_path('js/app.js')) }}" defer></script>
@stack('scripts')
</body>
</html>
