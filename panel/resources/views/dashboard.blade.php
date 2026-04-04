@extends('layouts.app')

@section('title', 'Dashboard — '.config('app.name'))

@section('content')
<div class="page-header">
    <h1>Dashboard</h1>
    <p class="subtitle">Manage your Garage S3 storage, API keys, and security settings.</p>
</div>

<div class="card-grid">
    <a href="{{ route('buckets.index') }}" class="card card-link">
        <h2>Buckets</h2>
        <p class="card-desc text-sm mb-0">Create, browse, and manage S3 buckets and their objects.</p>
    </a>
    <a href="{{ route('keys.index') }}" class="card card-link">
        <h2>API Keys</h2>
        <p class="card-desc text-sm mb-0">View and create Garage API keys via the CLI.</p>
    </a>
    <a href="{{ route('security.show') }}" class="card card-link">
        <h2>Security</h2>
        <p class="card-desc text-sm mb-0">Configure two-factor authentication for your account.</p>
    </a>
</div>
@endsection
