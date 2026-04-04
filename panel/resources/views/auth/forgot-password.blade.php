@extends('layouts.app')

@section('title', 'Forgot password — '.config('app.name'))

@section('content')
<div class="login-box card">
    <h1>Reset password</h1>
    <p class="muted">We will email a reset link if the account exists.</p>
    @if (session('status'))
        <div class="status">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="errors">{{ $errors->first() }}</div>
    @endif
    <form method="POST" action="{{ route('password.email') }}">
        @csrf
        <label for="email">Email</label>
        <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus>
        <div style="margin-top:1rem;">
            <button type="submit">Send link</button>
        </div>
    </form>
    <p class="muted" style="margin-top:1rem;"><a href="{{ route('login') }}">Back to login</a></p>
</div>
@endsection
