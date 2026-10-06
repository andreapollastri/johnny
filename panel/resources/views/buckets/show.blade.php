@extends('layouts.app')

@use('App\Support\Format')

@section('title', $bucket.' — '.config('app.name'))

@section('content')
<div class="page-header">
    <p class="crumb"><a href="{{ route('buckets.index') }}">&larr; All buckets</a></p>
    <h1 class="title-with-badge">
        {{ $bucket }}
        @if ($isProtected)
            <span class="badge badge-muted">protected</span>
        @endif
    </h1>
</div>

<div class="tabs">
    <a href="{{ route('buckets.show', ['bucket' => $bucket, 'tab' => 'objects']) }}" @class(['active' => $tab === 'objects'])>Objects</a>
    <a href="{{ route('buckets.show', ['bucket' => $bucket, 'tab' => 'keys']) }}" @class(['active' => $tab === 'keys'])>Access keys</a>
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
            $breadcrumbs = [];
            $cumulative = '';
            foreach (array_filter(explode('/', $prefix), 'strlen') as $part) {
                $cumulative .= $part.'/';
                $breadcrumbs[] = ['name' => $part, 'prefix' => $cumulative];
            }
            $parentPrefix = count($breadcrumbs) >= 2 ? $breadcrumbs[count($breadcrumbs) - 2]['prefix'] : '';
            $folderUrl = fn (string $p) => route('buckets.show', array_filter(['bucket' => $bucket, 'tab' => 'objects', 'prefix' => $p]));
        @endphp

        <div class="card">
            <div class="fm-breadcrumb">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
                @if (count($breadcrumbs) === 0)
                    <span class="active">{{ $bucket }}</span>
                @else
                    <a href="{{ $folderUrl('') }}">{{ $bucket }}</a>
                @endif
                @foreach ($breadcrumbs as $bc)
                    <span class="fm-breadcrumb-sep">/</span>
                    @if ($loop->last)
                        <span class="active">{{ $bc['name'] }}</span>
                    @else
                        <a href="{{ $folderUrl($bc['prefix']) }}">{{ $bc['name'] }}</a>
                    @endif
                @endforeach
            </div>

            <div class="fm-toolbar">
                <form method="POST" action="{{ route('objects.store', $bucket) }}" enctype="multipart/form-data" class="form-row">
                    @csrf
                    <input type="hidden" name="prefix" value="{{ $prefix }}">
                    <input type="file" name="files[]" multiple required aria-label="Files to upload">
                    <button type="submit">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                        Upload
                    </button>
                </form>
                <form method="POST" action="{{ route('objects.folder', $bucket) }}" class="form-row">
                    @csrf
                    <input type="hidden" name="prefix" value="{{ $prefix }}">
                    <input type="text" name="name" class="input-sm" placeholder="new-folder" required maxlength="255" pattern="[^/\\]+" title="Folder name without slashes" aria-label="New folder name">
                    <button type="submit" class="ghost">New folder</button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-head">
                <h2>Contents <span class="count">{{ count($folders) + count($objects) }}{{ $nextToken ? '+' : '' }}</span></h2>
            </div>
            @if (empty($folders) && empty($objects) && ! $prefix && ! $isPaged)
                <div class="empty-state">
                    <p>This bucket is empty. Upload files above or use any S3 client.</p>
                </div>
            @else
                <div class="table-wrap">
                <table class="fm-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th class="col-num">Size</th>
                            <th>Last modified</th>
                            <th class="col-actions"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @if ($prefix)
                            <tr class="fm-row-folder">
                                <td colspan="4">
                                    <a href="{{ $folderUrl($parentPrefix) }}" class="fm-entry fm-folder">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
                                        Parent folder
                                    </a>
                                </td>
                            </tr>
                        @endif
                        @foreach ($folders as $folder)
                            <tr class="fm-row-folder">
                                <td colspan="4">
                                    <a href="{{ $folderUrl($folder) }}" class="fm-entry fm-folder">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
                                        {{ basename(rtrim($folder, '/')) }}/
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                        @foreach ($objects as $obj)
                            <tr>
                                <td>
                                    <div class="fm-entry fm-file">
                                        <x-file-icon :name="$obj['key']" />
                                        <span class="fm-name">{{ basename($obj['key']) }}</span>
                                    </div>
                                </td>
                                <td class="muted fm-size col-num">{{ Format::bytes($obj['size']) }}</td>
                                <td class="muted text-sm" title="{{ $obj['last_modified'] }}">{{ Format::date($obj['last_modified']) }}</td>
                                <td class="col-actions">
                                    <div class="actions">
                                        <a href="{{ route('objects.download', ['bucket' => $bucket, 'key' => $obj['key']]) }}" class="btn btn-ghost btn-sm">Download</a>
                                        <form method="POST" action="{{ route('objects.destroy', $bucket) }}" data-confirm="Delete {{ basename($obj['key']) }}? This cannot be undone.">
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
                        @if (empty($folders) && empty($objects))
                            <tr><td colspan="4" class="muted empty-row">This folder is empty.</td></tr>
                        @endif
                    </tbody>
                </table>
                </div>

                @if ($nextToken || $isPaged)
                    <div class="pager">
                        @if ($isPaged)
                            <a href="{{ $folderUrl($prefix) }}" class="btn btn-ghost btn-sm">&larr; First page</a>
                        @endif
                        @if ($nextToken)
                            <a href="{{ route('buckets.show', array_filter(['bucket' => $bucket, 'tab' => 'objects', 'prefix' => $prefix, 'token' => $nextToken])) }}" class="btn btn-ghost btn-sm">Next page &rarr;</a>
                        @endif
                    </div>
                @endif
            @endif
        </div>
    @endif

@else
    {{-- ===== Access keys tab ===== --}}
    <div class="card">
        <h2>Grant access</h2>
        <p class="muted text-sm">Pick a key and the permissions it should have on <strong>{{ $bucket }}</strong>.</p>
        @if (count($allKeys) > 0)
            <form method="POST" action="{{ route('buckets.allow', $bucket) }}" class="grant-form">
                @csrf
                <div>
                    <label for="allow_key">Key</label>
                    <select id="allow_key" name="key_id" required>
                        @foreach ($allKeys as $k)
                            <option value="{{ $k['id'] }}" @selected(old('key_id') === $k['id'])>{{ $k['name'] }} — {{ $k['id'] }}</option>
                        @endforeach
                    </select>
                </div>
                <fieldset class="perm-toggles">
                    <legend class="sr-only">Permissions</legend>
                    <label class="perm-toggle"><input type="checkbox" name="read" value="1" checked> Read</label>
                    <label class="perm-toggle"><input type="checkbox" name="write" value="1" checked> Write</label>
                    <label class="perm-toggle"><input type="checkbox" name="owner" value="1"> Owner</label>
                </fieldset>
                <button type="submit">Grant</button>
            </form>
        @else
            <div class="empty-state">
                <p>No custom keys available. Create a key first from the <a href="{{ route('keys.index') }}">Keys</a> page.</p>
            </div>
        @endif
    </div>

    <div class="card">
        <div class="card-head">
            <h2>Authorized keys <span class="count">{{ count($authorizedKeys) }}</span></h2>
        </div>
        @if (empty($authorizedKeys))
            <div class="empty-state">
                <p>No custom keys have access to this bucket yet.</p>
            </div>
        @else
            <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Key ID</th>
                        <th>Permissions</th>
                        <th class="col-actions"></th>
                    </tr>
                </thead>
                <tbody>
                @foreach ($authorizedKeys as $k)
                    <tr>
                        <td><strong class="key-name">{{ $k['name'] ?: '—' }}</strong></td>
                        <td><code class="text-xs">{{ $k['id'] }}</code></td>
                        <td>
                            <span class="perm-chips">
                                @foreach (['read', 'write', 'owner'] as $perm)
                                    <span @class(['perm-chip', 'is-on' => str_contains($k['permissions'], $perm)])>{{ $perm }}</span>
                                @endforeach
                            </span>
                        </td>
                        <td class="col-actions">
                            <form method="POST" action="{{ route('buckets.deny', $bucket) }}" data-confirm="Revoke all permissions of {{ $k['name'] ?: $k['id'] }} on {{ $bucket }}?">
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

    <p class="muted text-xs">System keys (<code>johnny-default</code>, <code>johnny-backup</code>) are managed automatically and hidden here.</p>
@endif
@endsection
