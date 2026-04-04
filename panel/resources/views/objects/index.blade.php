@extends('layouts.app')

@section('title', 'Objects — '.$bucket)

@section('content')
<div class="page-header">
    <h1>{{ $bucket }}</h1>
    <p class="subtitle"><a href="{{ route('buckets.index') }}">&larr; All buckets</a></p>
</div>

@if (session('status'))
    <div class="status">{{ session('status') }}</div>
@endif
@if ($errors->any())
    <div class="errors">{{ $errors->first() }}</div>
@endif

<div class="card">
    <h2>Filter by prefix</h2>
    <form method="GET" action="{{ route('objects.index', $bucket) }}" class="form-row">
        <input type="text" name="prefix" value="{{ $prefix }}" placeholder="folder/">
        <button type="submit" class="secondary">Apply</button>
    </form>
</div>

<div class="card">
    <h2>Upload</h2>
    <form method="POST" action="{{ route('objects.store', $bucket) }}" enctype="multipart/form-data" class="form-row">
        @csrf
        <input type="hidden" name="prefix" value="{{ $prefix }}">
        <input type="file" name="file" required>
        <button type="submit">Upload</button>
    </form>
</div>

<div class="card">
    <h2>Objects</h2>
    @if (empty($objects))
        <p class="muted mb-0">No objects with this prefix.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>Key</th>
                    <th>Size</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            @foreach ($objects as $obj)
                <tr>
                    <td><code>{{ $obj['key'] }}</code></td>
                    <td class="muted">{{ number_format($obj['size']) }} B</td>
                    <td>
                        <div class="actions">
                            <a href="{{ route('objects.download', $bucket) }}?key={{ urlencode($obj['key']) }}" class="btn-ghost btn-sm" style="padding:0.25rem 0.5rem; border:1px solid var(--border); border-radius:var(--radius-sm); font-size:0.75rem;">Download</a>
                            <form method="POST" action="{{ route('objects.destroy', $bucket) }}" onsubmit="return confirm('Delete this object?');" style="margin:0;">
                                @csrf
                                @method('DELETE')
                                <input type="hidden" name="key" value="{{ $obj['key'] }}">
                                <input type="hidden" name="prefix" value="{{ $prefix }}">
                                <button type="submit" class="danger sm">Delete</button>
                            </form>
                        </div>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
</div>
@endsection
