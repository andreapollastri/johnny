@extends('layouts.app')

@section('title', 'Objects — '.$bucket)

@section('content')
<h1>Bucket: {{ $bucket }}</h1>
<p class="muted">Prefix filter (optional folder path):</p>
<form method="GET" action="{{ route('objects.index', $bucket) }}" class="stack" style="margin-bottom:1rem;">
    <input type="text" name="prefix" value="{{ $prefix }}" placeholder="folder/">
    <button type="submit" class="secondary">Apply</button>
</form>

@if (session('status'))
    <div class="status">{{ session('status') }}</div>
@endif
@if ($errors->any())
    <div class="errors">{{ $errors->first() }}</div>
@endif

<div class="card">
    <h2>Upload</h2>
    <form method="POST" action="{{ route('objects.store', $bucket) }}" enctype="multipart/form-data" class="stack">
        @csrf
        <input type="hidden" name="prefix" value="{{ $prefix }}">
        <input type="file" name="file" required>
        <button type="submit">Upload</button>
    </form>
</div>

<div class="card">
    <h2>Objects</h2>
    @if (empty($objects))
        <p class="muted">No objects with this prefix.</p>
    @else
        <table>
            <thead><tr><th>Key</th><th>Size</th><th></th></tr></thead>
            <tbody>
            @foreach ($objects as $obj)
                <tr>
                    <td><code>{{ $obj['key'] }}</code></td>
                    <td class="muted">{{ number_format($obj['size']) }} B</td>
                    <td class="stack">
                        <a href="{{ route('objects.download', $bucket) }}?key={{ urlencode($obj['key']) }}">Download</a>
                        <form method="POST" action="{{ route('objects.destroy', $bucket) }}" style="display:inline;" onsubmit="return confirm('Delete this object?');">
                            @csrf
                            @method('DELETE')
                            <input type="hidden" name="key" value="{{ $obj['key'] }}">
                            <input type="hidden" name="prefix" value="{{ $prefix }}">
                            <button type="submit" class="danger" style="padding:0.2rem 0.5rem;">Delete</button>
                        </form>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
</div>
<p><a href="{{ route('buckets.index') }}">← Buckets</a></p>
@endsection
