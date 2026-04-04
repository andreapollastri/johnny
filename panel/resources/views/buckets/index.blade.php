@extends('layouts.app')

@section('title', 'Buckets — '.config('app.name'))

@section('content')
<div class="page-header">
    <h1>Buckets</h1>
    <p class="subtitle">Create and manage your Garage S3 buckets.</p>
</div>

@if (session('status'))
    <div class="status">{{ session('status') }}</div>
@endif
@if ($errors->any())
    <div class="errors">{{ $errors->first() }}</div>
@endif
@if (!empty($error))
    <div class="errors">{{ $error }}</div>
@endif

<div class="card">
    <h2>Create bucket</h2>
    <form method="POST" action="{{ route('buckets.store') }}" class="form-row">
        @csrf
        <input type="text" name="name" value="{{ old('name') }}" placeholder="bucket-name" required pattern="[a-z0-9][a-z0-9._-]*" title="Lowercase letters, digits, dot, dash">
        <button type="submit">Create</button>
    </form>
</div>

<div class="card">
    <h2>All buckets</h2>
    @if (empty($buckets))
        <div class="empty-state">
            <p>No buckets yet.</p>
        </div>
    @else
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Created</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            @foreach ($buckets as $b)
                <tr>
                    <td><a href="{{ route('buckets.show', $b['name']) }}">{{ $b['name'] }}</a></td>
                    <td class="muted">{{ $b['created'] ?? '—' }}</td>
                    <td>
                        @if ($b['name'] === 'default')
                            <span class="muted text-xs">protected</span>
                        @else
                            <form method="POST" action="{{ route('buckets.destroy', $b['name']) }}" onsubmit="return confirm('Delete bucket {{ $b['name'] }}? Must be empty.');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="danger sm">Delete</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
</div>
@endsection
