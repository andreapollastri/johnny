<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', config('app.name'))</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    <script>
    (function(){
        var t = localStorage.getItem('theme');
        if (t === 'dark' || t === 'light') document.documentElement.setAttribute('data-theme', t);
    })();
    </script>
</head>
<body>
<div class="shell">
@auth
    <header class="topbar">
        <a href="{{ route('dashboard') }}" class="topbar-brand">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
            {{ config('app.name') }}
        </a>
        <nav class="topbar-nav">
            <a href="{{ route('dashboard') }}" @class(['active' => request()->routeIs('dashboard')])>Dashboard</a>
            <a href="{{ route('buckets.index') }}" @class(['active' => request()->routeIs('buckets.*') || request()->routeIs('objects.*')])>Buckets</a>
            <a href="{{ route('keys.index') }}" @class(['active' => request()->routeIs('keys.*')])>Keys</a>
            <a href="{{ route('security.show') }}" @class(['active' => request()->routeIs('security.*')])>Security</a>
            <span class="nav-sep"></span>
            <button type="button" class="theme-toggle" onclick="toggleTheme()" title="Toggle theme">
                <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
                <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
            </button>
            <form method="POST" action="{{ route('logout') }}" style="display:inline; margin:0;">
                @csrf
                <button type="submit" class="secondary sm">Log out</button>
            </form>
        </nav>
    </header>
@endauth

<main class="@auth wrap @else auth-wrapper @endauth">
    @yield('content')
</main>

@auth
<footer class="site-footer">
    Johnny &middot; Garage S3 Storage
</footer>
@endauth
</div>

<script>
function toggleTheme() {
    var html = document.documentElement;
    var current = html.getAttribute('data-theme');
    var next;
    if (current === 'dark') next = 'light';
    else if (current === 'light') next = 'dark';
    else next = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'light' : 'dark';
    html.setAttribute('data-theme', next);
    localStorage.setItem('theme', next);
}
</script>
</body>
</html>
