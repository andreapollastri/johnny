@extends('layouts.app')

@section('title', 'Confirm password — '.config('app.name'))

@section('content')
<div class="login-box card">
    <h1>Confirm password</h1>
    <p class="muted">This area requires your current password.</p>
    @if ($errors->any())
        <div class="errors">{{ $errors->first() }}</div>
    @endif
    <form method="POST" action="{{ route('password.confirm.store') }}">
        @csrf
        <label for="password">Password</label>
        <input id="password" type="password" name="password" required autocomplete="current-password" autofocus>
        <div style="margin-top:1rem;">
            <button type="submit">Confirm</button>
        </div>
    </form>
</div>
@endsection
