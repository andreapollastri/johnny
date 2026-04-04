@extends('layouts.app')

@section('title', 'New password — '.config('app.name'))

@section('content')
<div class="login-box card">
    <h1>Set a new password</h1>
    @if ($errors->any())
        <div class="errors">{{ $errors->first() }}</div>
    @endif
    <form method="POST" action="{{ route('password.update') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">
        <label for="email">Email</label>
        <input id="email" type="email" name="email" value="{{ old('email', $request->email) }}" required autocomplete="username">
        <label for="password" style="margin-top:0.75rem;">New password</label>
        <input id="password" type="password" name="password" required autocomplete="new-password">
        <label for="password_confirmation" style="margin-top:0.75rem;">Confirm password</label>
        <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password">
        <div style="margin-top:1rem;">
            <button type="submit">Update password</button>
        </div>
    </form>
</div>
@endsection
