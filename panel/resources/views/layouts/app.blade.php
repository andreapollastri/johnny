<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', config('app.name'))</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
@if(auth()->check())
    <header class="nav">
        <span class="brand">{{ config('app.name') }}</span>
        <nav>
            <a href="{{ route('dashboard') }}">Dashboard</a>
            <a href="{{ route('buckets.index') }}">Buckets</a>
            <a href="{{ route('keys.index') }}">Keys</a>
            <a href="{{ route('security.show') }}">Security</a>
            <form method="POST" action="{{ route('logout') }}" style="display:inline;">
                @csrf
                <button type="submit" class="secondary" style="padding:0.25rem 0.5rem;">Log out</button>
            </form>
        </nav>
    </header>
@endif
<main class="wrap">
    @yield('content')
</main>
</body>
</html>
