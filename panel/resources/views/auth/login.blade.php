@extends('layouts.app')

@section('title', 'Login — '.config('app.name'))

@section('content')
<div class="login-box card">
    <h1>Sign in</h1>
    @if ($errors->any())
        <div class="errors">{{ $errors->first() }}</div>
    @endif
    <form method="POST" action="{{ route('login.store') }}">
        @csrf
        <label for="email">Email</label>
        <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
        <label for="password" style="margin-top:0.75rem;">Password</label>
        <input id="password" type="password" name="password" required autocomplete="current-password">
        <label style="margin-top:0.5rem; display:flex; align-items:center; gap:0.35rem;">
            <input type="checkbox" name="remember" value="1"> <span class="muted">Remember me</span>
        </label>
        <div style="margin-top:1rem;">
            <button type="submit">Sign in</button>
        </div>
    </form>
    <p class="muted" style="margin-top:1rem;"><a href="{{ route('password.request') }}">Forgot password?</a></p>
</div>
@endsection
