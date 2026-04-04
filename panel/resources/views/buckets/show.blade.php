@extends('layouts.app')

@section('title', $bucket.' — '.config('app.name'))

@section('content')
<div class="page-header">
    <h1>{{ $bucket }}</h1>
    <p class="subtitle"><a href="{{ route('buckets.index') }}">&larr; All buckets</a></p>
</div>

<div class="tabs">
    <a href="{{ route('buckets.show', ['bucket' => $bucket, 'tab' => 'objects']) }}" @class(['active' => $tab === 'objects'])>Objects</a>
    <a href="{{ route('buckets.show', ['bucket' => $bucket, 'tab' => 'keys']) }}" @class(['active' => $tab === 'keys'])>Keys</a>
</div>

@if (session('status'))
    <div class="status">{{ session('status') }}</div>
@endif
@if ($errors->any())
    <div class="errors">{{ $errors->first() }}</div>
@endif

@if ($tab === 'objects')
    {{-- ===== Objects tab ===== --}}

    @if ($objectsError)
        <div class="errors">{{ $objectsError }}</div>
    @else
        <div class="card">
            <h2>Filter by prefix</h2>
            <form method="GET" action="{{ route('buckets.show', $bucket) }}" class="form-row">
                <input type="hidden" name="tab" value="objects">
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
                <div class="empty-state">
                    <p>No objects{{ $prefix ? ' with prefix "'.$prefix.'"' : '' }}.</p>
                </div>
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
                                <a href="{{ route('objects.download', $bucket) }}?key={{ urlencode($obj['key']) }}" class="ghost sm" style="padding:0.25rem 0.5rem; border:1px solid var(--border); border-radius:var(--radius-sm); font-size:0.75rem;">Download</a>
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
    @endif

@else
    {{-- ===== Keys tab ===== --}}
    <div class="card">
        <h2>Grant access</h2>
        <p class="muted text-sm" style="margin-bottom:0.75rem;">Select a key and the permissions to grant on this bucket.</p>
        @if (count($allKeys) > 0)
            <form method="POST" action="{{ route('buckets.allow', $bucket) }}">
                @csrf
                <div class="form-row" style="margin-bottom:0.5rem;">
                    <div>
                        <label for="allow_key">Key</label>
                        <select id="allow_key" name="key_id" required style="max-width:20rem;">
                            @foreach ($allKeys as $k)
                                <option value="{{ $k['id'] }}">{{ $k['name'] }} ({{ $k['id'] }})</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="form-row" style="margin-bottom:0.75rem;">
                    <label style="display:flex; align-items:center; gap:0.3rem; cursor:pointer;">
                        <input type="checkbox" name="read" value="1" checked> <span class="text-sm">Read</span>
                    </label>
                    <label style="display:flex; align-items:center; gap:0.3rem; cursor:pointer;">
                        <input type="checkbox" name="write" value="1" checked> <span class="text-sm">Write</span>
                    </label>
                    <label style="display:flex; align-items:center; gap:0.3rem; cursor:pointer;">
                        <input type="checkbox" name="owner" value="1" checked> <span class="text-sm">Owner</span>
                    </label>
                </div>
                <button type="submit">Grant</button>
            </form>
        @else
            <div class="empty-state">
                <p>No custom keys available. Create a key first from the <a href="{{ route('keys.index') }}">Keys</a> page.</p>
            </div>
        @endif
    </div>

    <div class="card">
        <h2>Authorized keys</h2>
        @if (empty($authorizedKeys))
            <div class="empty-state">
                <p>No custom keys have access to this bucket yet.</p>
            </div>
        @else
            <table>
                <thead>
                    <tr>
                        <th>Key ID</th>
                        <th>Name</th>
                        <th>Permissions</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                @foreach ($authorizedKeys as $k)
                    <tr>
                        <td><code class="text-xs">{{ $k['id'] }}</code></td>
                        <td>{{ $k['name'] ?: '—' }}</td>
                        <td class="muted">{{ $k['permissions'] }}</td>
                        <td>
                            <form method="POST" action="{{ route('buckets.deny', $bucket) }}" onsubmit="return confirm('Revoke all permissions for {{ $k['name'] ?: $k['id'] }}?');" style="margin:0;">
                                @csrf
                                <input type="hidden" name="key_id" value="{{ $k['id'] }}">
                                <input type="hidden" name="read" value="1">
                                <input type="hidden" name="write" value="1">
                                <input type="hidden" name="owner" value="1">
                                <button type="submit" class="danger sm">Revoke</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endif
@endsection
