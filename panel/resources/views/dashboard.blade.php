@extends('layouts.app')

@section('title', 'Dashboard — '.config('app.name'))

@section('content')
<h1>Dashboard</h1>
<p class="muted">Manage Garage buckets, objects, and API keys. Configure two-factor authentication under <a href="{{ route('security.show') }}">Security</a>.</p>
<div class="card">
    <h2>Quick links</h2>
    <ul>
        <li><a href="{{ route('buckets.index') }}">Buckets</a></li>
        <li><a href="{{ route('keys.index') }}">API keys (CLI)</a></li>
        <li><a href="{{ route('security.show') }}">Security &amp; 2FA</a></li>
    </ul>
</div>
@endsection
