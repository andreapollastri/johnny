@extends('layouts.app')

@section('title', 'Buckets — '.config('app.name'))

@section('content')
<h1>Buckets</h1>
@if (session('status'))
    <div class="status">{{ session('status') }}</div>
@endif
@if ($errors->any())
    <div class="errors">{{ $errors->first() }}</div>
@endif

<div class="card">
    <h2>Create bucket</h2>
    <form method="POST" action="{{ route('buckets.store') }}" class="stack">
        @csrf
        <input type="text" name="name" value="{{ old('name') }}" placeholder="bucket-name" required pattern="[a-z0-9][a-z0-9._-]*" title="Lowercase letters, digits, dot, dash">
        <button type="submit">Create</button>
    </form>
</div>

<div class="card">
    <h2>All buckets</h2>
    @if (empty($buckets))
        <p class="muted">No buckets yet.</p>
    @else
        <table>
            <thead><tr><th>Name</th><th>Created</th><th></th></tr></thead>
            <tbody>
            @foreach ($buckets as $b)
                <tr>
                    <td><a href="{{ route('objects.index', ['bucket' => $b['name']]) }}">{{ $b['name'] }}</a></td>
                    <td class="muted">{{ $b['created'] ?? '—' }}</td>
                    <td>
                        <form method="POST" action="{{ route('buckets.destroy', $b['name']) }}" onsubmit="return confirm('Delete bucket {{ $b['name'] }}? Must be empty.');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="danger" style="padding:0.2rem 0.5rem;">Delete</button>
                        </form>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
</div>
@endsection
