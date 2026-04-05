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
        @php
            $formatSize = function ($bytes) {
                if ($bytes === 0) return '0 B';
                $units = ['B', 'KB', 'MB', 'GB', 'TB'];
                $i = (int) floor(log($bytes, 1024));
                $i = min($i, count($units) - 1);
                $value = $bytes / pow(1024, $i);
                return ($i === 0 ? (int) $value : number_format($value, 2)) . ' ' . $units[$i];
            };

            $breadcrumbs = [];
            if ($prefix) {
                $parts = array_filter(explode('/', $prefix));
                $cumulative = '';
                foreach ($parts as $part) {
                    $cumulative .= $part . '/';
                    $breadcrumbs[] = ['name' => $part, 'prefix' => $cumulative];
                }
            }

            $parentPrefix = '';
            if (count($breadcrumbs) >= 2) {
                $parentPrefix = $breadcrumbs[count($breadcrumbs) - 2]['prefix'];
            }
        @endphp

        <div class="card">
            <div class="fm-breadcrumb">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
                <a href="{{ route('buckets.show', ['bucket' => $bucket, 'tab' => 'objects']) }}" @class(['active' => !$prefix])>/</a>
                @foreach ($breadcrumbs as $bc)
                    <span class="fm-breadcrumb-sep">&rsaquo;</span>
                    @if ($loop->last)
                        <span class="active">{{ $bc['name'] }}</span>
                    @else
                        <a href="{{ route('buckets.show', ['bucket' => $bucket, 'tab' => 'objects', 'prefix' => $bc['prefix']]) }}">{{ $bc['name'] }}</a>
                    @endif
                @endforeach
            </div>
        </div>

        <div class="card">
            <h2>Upload to {{ $prefix ?: '/' }}</h2>
            <form method="POST" action="{{ route('objects.store', $bucket) }}" enctype="multipart/form-data" class="form-row">
                @csrf
                <input type="hidden" name="prefix" value="{{ $prefix }}">
                <input type="file" name="file" required>
                <button type="submit">Upload</button>
            </form>
        </div>

        <div class="card">
            <h2>Contents</h2>
            @if (empty($folders) && empty($objects))
                <div class="empty-state">
                    <p>This folder is empty.</p>
                </div>
            @else
                <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Size</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @if ($prefix)
                            <tr class="fm-row-folder">
                                <td colspan="3">
                                    <a href="{{ route('buckets.show', ['bucket' => $bucket, 'tab' => 'objects', 'prefix' => $parentPrefix ?: '']) }}" class="fm-entry fm-folder">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
                                        ..
                                    </a>
                                </td>
                            </tr>
                        @endif
                        @foreach ($folders as $folder)
                            <tr class="fm-row-folder">
                                <td colspan="2">
                                    <a href="{{ route('buckets.show', ['bucket' => $bucket, 'tab' => 'objects', 'prefix' => $folder]) }}" class="fm-entry fm-folder">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
                                        {{ basename(rtrim($folder, '/')) }}
                                    </a>
                                </td>
                                <td></td>
                            </tr>
                        @endforeach
                        @foreach ($objects as $obj)
                            <tr>
                                <td>
                                    <div class="fm-entry fm-file">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                        <code>{{ basename($obj['key']) }}</code>
                                    </div>
                                </td>
                                <td class="muted fm-size">{{ $formatSize($obj['size']) }}</td>
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
                </div>
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
            <div class="table-wrap">
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
            </div>
        @endif
    </div>
@endif
@endsection
