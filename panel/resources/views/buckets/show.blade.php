@extends('layouts.app')

@use('App\Support\Format')
@use('App\Support\KeyScopes')
@use('App\Http\Controllers\BucketSettingsController')

@section('title', $bucket.' — '.config('app.name'))

@section('crumbs')
    <a href="{{ route('buckets.index') }}">Buckets</a>
    <span class="sep">/</span>
    <span>{{ $bucket }}</span>
@endsection

@section('content')
<div class="page-head">
    <h1 class="page-title">
        {{ $bucket }}
        @if ($isProtected)
            <span class="badge badge-muted">protected</span>
        @endif
    </h1>
</div>

<nav class="tabs" aria-label="Bucket">
    <a href="{{ route('buckets.show', ['bucket' => $bucket, 'tab' => 'objects']) }}" @class(['tab', 'is-active' => $tab === 'objects'])>Objects</a>
    <a href="{{ route('buckets.show', ['bucket' => $bucket, 'tab' => 'keys']) }}" @class(['tab', 'is-active' => $tab === 'keys'])>Access keys</a>
    <a href="{{ route('buckets.show', ['bucket' => $bucket, 'tab' => 'settings']) }}" @class(['tab', 'is-active' => $tab === 'settings'])>Settings</a>
    <a href="{{ route('buckets.show', ['bucket' => $bucket, 'tab' => 'connect']) }}" @class(['tab', 'is-active' => $tab === 'connect'])>Connect</a>
</nav>

@if ($tab === 'objects')
    {{-- ===== Objects ===== --}}
    @if ($objectsError)
        <div class="flash flash-err" role="alert"><span>{{ $objectsError }}</span></div>
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
            $isEmpty = empty($folders) && empty($objects);
        @endphp

        <section class="card card-flush fm" data-fm
            data-bucket="{{ $bucket }}"
            data-prefix="{{ $prefix }}"
            data-folder-url="{{ $folderUrl($prefix) }}"
            data-chunk-url="{{ route('objects.chunk', $bucket) }}"
            data-chunk-bytes="{{ $chunkBytes }}"
            data-info-url="{{ route('objects.info', $bucket) }}"
            data-share-url="{{ route('objects.share', $bucket) }}">

            <div class="fm-bar">
                <div class="fm-path">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
                    @if (count($breadcrumbs) === 0)
                        <span class="active">{{ $bucket }}</span>
                    @else
                        <a href="{{ $folderUrl('') }}">{{ $bucket }}</a>
                    @endif
                    @foreach ($breadcrumbs as $bc)
                        <span class="sep">/</span>
                        @if ($loop->last)
                            <span class="active">{{ $bc['name'] }}</span>
                        @else
                            <a href="{{ $folderUrl($bc['prefix']) }}">{{ $bc['name'] }}</a>
                        @endif
                    @endforeach
                </div>
                <div class="fm-tools">
                    <form method="GET" action="{{ route('buckets.show', $bucket) }}" class="inline-form" role="search">
                        <input type="hidden" name="tab" value="objects">
                        @if ($prefix)
                            <input type="hidden" name="prefix" value="{{ $prefix }}">
                        @endif
                        <input type="search" name="q" value="{{ $search }}" class="input-sm" placeholder="Names starting with…" aria-label="Show names that start with">
                    </form>
                    <button type="button" class="btn btn-sm" data-toggle="#fm-new-folder" aria-expanded="false">New folder</button>
                    <button type="button" class="btn btn-primary btn-sm" data-fm-upload>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                        Upload
                    </button>
                    <input type="file" id="fm-files" multiple hidden aria-label="Files to upload">
                </div>
            </div>

            <form method="POST" action="{{ route('objects.folder', $bucket) }}" class="fm-new-folder" id="fm-new-folder" hidden>
                @csrf
                <input type="hidden" name="prefix" value="{{ $prefix }}">
                <label for="fm-folder-name" class="mb-0">Folder name</label>
                <input type="text" id="fm-folder-name" name="name" class="input-sm input-mono" placeholder="new-folder" required maxlength="255" pattern="[^/\\]+" title="A name without slashes">
                <button type="submit" class="btn btn-sm">Create folder</button>
            </form>

            <div class="fm-queue" id="fm-queue" hidden>
                <div class="fm-queue-head">
                    <span id="fm-queue-title">Uploading</span>
                    <a href="{{ $folderUrl($prefix) }}" class="btn btn-sm" id="fm-queue-done" hidden>Show the files</a>
                </div>
                <ul class="fm-queue-list" id="fm-queue-list"></ul>
            </div>

            <noscript>
                <form method="POST" action="{{ route('objects.store', $bucket) }}" enctype="multipart/form-data" class="fm-new-folder">
                    @csrf
                    <input type="hidden" name="prefix" value="{{ $prefix }}">
                    <input type="file" name="files[]" multiple required aria-label="Files to upload">
                    <button type="submit" class="btn btn-sm">Upload</button>
                </form>
            </noscript>

            @if ($isEmpty && ! $prefix && ! $isPaged && $search === '')
                <div class="empty">
                    <h3>This bucket is empty</h3>
                    <p>Drop files anywhere on this panel, use Upload, or point any S3 client at it — the Connect tab has the settings.</p>
                </div>
            @else
                <form method="POST" action="{{ route('objects.destroy-many', $bucket) }}" id="fm-bulk" data-confirm="Delete the selected items? This cannot be undone." data-confirm-title="Delete" data-confirm-label="Delete" data-confirm-danger>
                    @csrf
                    <input type="hidden" name="prefix" value="{{ $prefix }}">
                    <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th class="col-check"><input type="checkbox" data-fm-check-all aria-label="Select everything on this page"></th>
                                <th>Name</th>
                                <th class="col-num">Size</th>
                                <th class="hide-sm">Last modified</th>
                                <th class="col-actions"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @if ($prefix)
                                <tr>
                                    <td class="col-check"></td>
                                    <td colspan="4">
                                        <a href="{{ $folderUrl($parentPrefix) }}" class="fm-entry fm-folder">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
                                            Parent folder
                                        </a>
                                    </td>
                                </tr>
                            @endif
                            @foreach ($folders as $folder)
                                <tr>
                                    <td class="col-check"><input type="checkbox" class="fm-check" name="folders[]" value="{{ $folder }}" aria-label="Select folder {{ basename(rtrim($folder, '/')) }}"></td>
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
                                    <td class="col-check"><input type="checkbox" class="fm-check" name="keys[]" value="{{ $obj['key'] }}" aria-label="Select {{ basename($obj['key']) }}"></td>
                                    <td>
                                        <span class="fm-entry fm-file">
                                            <x-file-icon :name="$obj['key']" />
                                            <button type="button" class="fm-name" data-fm-open="{{ $obj['key'] }}" title="Details and preview">{{ basename($obj['key']) }}</button>
                                        </span>
                                    </td>
                                    <td class="muted fm-size col-num">{{ Format::bytes($obj['size']) }}</td>
                                    <td class="muted text-sm nowrap hide-sm" title="{{ $obj['last_modified'] }}">{{ Format::date($obj['last_modified']) }}</td>
                                    <td class="col-actions">
                                        <a href="{{ route('objects.download', ['bucket' => $bucket, 'key' => $obj['key']]) }}" class="btn btn-ghost btn-sm">Download</a>
                                    </td>
                                </tr>
                            @endforeach
                            @if ($isEmpty)
                                <tr><td colspan="5" class="empty-row">{{ $search !== '' ? 'No name here starts with "'.$search.'".' : 'This folder is empty.' }}</td></tr>
                            @endif
                        </tbody>
                    </table>
                    </div>

                    <div class="fm-select-bar" id="fm-select-bar" hidden>
                        <span id="fm-select-count">0 selected</span>
                        <button type="button" class="btn btn-sm" data-fm-clear>Clear</button>
                        <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                    </div>
                </form>

                @if ($nextToken || $isPaged)
                    <div class="pager">
                        @if ($isPaged)
                            <a href="{{ route('buckets.show', array_filter(['bucket' => $bucket, 'tab' => 'objects', 'prefix' => $prefix, 'q' => $search])) }}" class="btn btn-sm">First page</a>
                        @endif
                        @if ($nextToken)
                            <a href="{{ route('buckets.show', array_filter(['bucket' => $bucket, 'tab' => 'objects', 'prefix' => $prefix, 'q' => $search, 'token' => $nextToken])) }}" class="btn btn-sm">Next page</a>
                        @endif
                    </div>
                @endif
            @endif

            <div class="fm-drop" id="fm-drop" hidden>Drop to upload into {{ $bucket }}/{{ $prefix }}</div>
        </section>

        {{-- Details of one object: filled in by js/browser.js --}}
        <div class="scrim" id="fm-scrim" hidden></div>
        <aside class="drawer" id="fm-drawer" hidden aria-label="File details">
            <div class="drawer-head">
                <h2 class="drawer-title" data-d="name"></h2>
                <button type="button" class="icon-btn" data-drawer-close aria-label="Close">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>
            <div class="drawer-body">
                <div class="preview" data-d="preview" hidden></div>
                <dl class="kv">
                    <dt>Key</dt><dd class="mono text-sm" data-d="key"></dd>
                    <dt>Size</dt><dd data-d="size"></dd>
                    <dt>Last modified</dt><dd data-d="modified"></dd>
                    <dt>Content type</dt><dd data-d="type"></dd>
                    <dt>ETag</dt><dd class="mono text-sm" data-d="etag"></dd>
                    <dt>S3 URI</dt>
                    <dd><span class="inline-copy"><span class="mono text-sm" id="fm-uri" data-d="uri"></span> <button type="button" class="btn btn-xs" data-copy-from="#fm-uri">Copy</button></span></dd>
                </dl>
            </div>
            <div class="drawer-foot">
                <a href="#" class="btn btn-primary" data-d="download">Download</a>
                <button type="button" class="btn" data-fm-share>Share link</button>
                <button type="button" class="btn" data-fm-move>Rename or move</button>
                <form method="POST" action="{{ route('objects.destroy', $bucket) }}" class="inline-form" data-confirm="Delete this file? This cannot be undone." data-confirm-title="Delete">
                    @csrf
                    @method('DELETE')
                    <input type="hidden" name="key" data-d-key>
                    <input type="hidden" name="prefix" value="{{ $prefix }}">
                    <button type="submit" class="btn btn-danger">Delete</button>
                </form>
            </div>
        </aside>

        <dialog id="fm-share" aria-labelledby="fm-share-title">
            <div class="dialog-body">
                <h2 class="dialog-title" id="fm-share-title">Share <span data-share-name></span></h2>
                <p class="text-sm">A share link lets anyone download this one file, without a key, until it expires.</p>
                <div class="field mt-3">
                    <label for="fm-share-expires">The link works for</label>
                    <select id="fm-share-expires">
                        @foreach ($shareLifetimes as $seconds => $label)
                            <option value="{{ $seconds }}" @selected($seconds === 86400)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field" data-share-result hidden>
                    <label for="fm-share-url">Link</label>
                    <input type="text" id="fm-share-url" class="input-mono" data-share-url readonly>
                    <p class="field-hint" data-share-note></p>
                </div>
            </div>
            <div class="dialog-actions">
                <button type="button" class="btn" data-dialog-close>Close</button>
                <button type="button" class="btn" data-copy-from="#fm-share-url">Copy link</button>
                <button type="button" class="btn btn-primary" data-share-create>Create link</button>
            </div>
        </dialog>

        <dialog id="fm-move" aria-labelledby="fm-move-title">
            <form method="POST" action="{{ route('objects.move', $bucket) }}">
                @csrf
                <input type="hidden" name="key">
                <input type="hidden" name="prefix" value="{{ $prefix }}">
                <div class="dialog-body">
                    <h2 class="dialog-title" id="fm-move-title">Rename or move</h2>
                    <div class="field">
                        <label for="fm-move-to">New path in {{ $bucket }}</label>
                        <input type="text" id="fm-move-to" name="to" class="input-mono" required maxlength="1024" autocomplete="off" spellcheck="false">
                        <p class="field-hint">Change the name, or the folders before it to move the file. Folders that do not exist are created.</p>
                    </div>
                </div>
                <div class="dialog-actions">
                    <button type="button" class="btn" data-dialog-close>Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>
        </dialog>

        @push('scripts')
            <script src="{{ asset('js/browser.js') }}?v={{ filemtime(public_path('js/browser.js')) }}" defer></script>
        @endpush
    @endif

@elseif ($tab === 'keys')
    {{-- ===== Access keys ===== --}}
    @if ($newKey = session('new_key'))
        @include('partials.new-key', ['newKey' => $newKey])
    @endif

    <div class="grid-2">
        <section class="card">
            <h2>New key for this bucket</h2>
            <p class="card-sub">Credentials that work on <strong>{{ $bucket }}</strong> and nowhere else.</p>
            <form method="POST" action="{{ route('buckets.keys.store', $bucket) }}">
                @csrf
                <div class="field">
                    <label for="new_key_name">Name</label>
                    <input type="text" id="new_key_name" name="name" class="input-mono" value="{{ old('name') }}" placeholder="{{ $bucket }}-app" required maxlength="128" pattern="[a-zA-Z0-9][a-zA-Z0-9._\-]*" title="Letters, digits, dot, dash and underscore">
                </div>
                <div class="field">
                    <label for="new_key_scope">What it may do</label>
                    <select id="new_key_scope" name="scope">
                        @foreach ($scopes as $value => $scope)
                            <option value="{{ $value }}" @selected(old('scope', 'read-write') === $value)>{{ $scope['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Create key</button>
                </div>
            </form>
        </section>

        <section class="card">
            <h2>Grant access to an existing key</h2>
            <p class="card-sub">The key gets exactly this scope here, replacing what it had.</p>
            @if (count($allKeys) > 0)
                <form method="POST" action="{{ route('buckets.allow', $bucket) }}">
                    @csrf
                    <div class="field">
                        <label for="allow_key">Key</label>
                        <select id="allow_key" name="key_id" required>
                            @foreach ($allKeys as $k)
                                <option value="{{ $k['id'] }}" @selected(old('key_id') === $k['id'])>{{ $k['name'] }} — {{ $k['id'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="allow_scope">What it may do</label>
                        <select id="allow_scope" name="scope">
                            @foreach ($scopes as $value => $scope)
                                <option value="{{ $value }}" @selected($value === 'read-write')>{{ $scope['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn">Grant access</button>
                    </div>
                </form>
            @else
                <p class="muted">There are no other keys yet. Create the first one here, or a key for all buckets from <a href="{{ route('keys.index') }}#create">Access keys</a>.</p>
            @endif
        </section>
    </div>

    <section class="card card-flush">
        <div class="card-head">
            <h2>Keys with access <span class="count">{{ count($authorizedKeys) }}</span></h2>
        </div>
        @if (empty($authorizedKeys))
            <div class="empty">
                <p>No application key can use this bucket yet.</p>
            </div>
        @else
            <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th class="hide-sm">Key ID</th>
                        <th>Access</th>
                        <th class="col-actions"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                @foreach ($authorizedKeys as $k)
                    <tr>
                        <td>
                            <a href="{{ route('keys.show', $k['id']) }}" class="cell-main key-name">{{ $k['name'] ?: '—' }}</a>
                            @if (isset($globalKeys[$k['id']]))
                                <span class="badge">All buckets</span>
                            @endif
                        </td>
                        <td class="hide-sm"><code class="text-xs">{{ $k['id'] }}</code></td>
                        <td>
                            {{ KeyScopes::describe($k['permissions']) }}
                            <span class="perm-chips">
                                @foreach (['read', 'write', 'owner'] as $perm)
                                    <span @class(['perm-chip', 'is-on' => str_contains($k['permissions'], $perm)])>{{ $perm }}</span>
                                @endforeach
                            </span>
                        </td>
                        <td class="col-actions">
                            @if (isset($globalKeys[$k['id']]))
                                <a href="{{ route('keys.show', $k['id']) }}" class="btn btn-sm">Manage</a>
                            @else
                                <form method="POST" action="{{ route('buckets.deny', $bucket) }}" class="inline-form" data-confirm="Revoke all permissions of {{ $k['name'] ?: $k['id'] }} on {{ $bucket }}?">
                                    @csrf
                                    <input type="hidden" name="key_id" value="{{ $k['id'] }}">
                                    <input type="hidden" name="read" value="1">
                                    <input type="hidden" name="write" value="1">
                                    <input type="hidden" name="owner" value="1">
                                    <button type="submit" class="btn btn-danger btn-sm">Revoke</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            </div>
        @endif
    </section>

    @include('partials.scope-legend')

    <p class="muted text-xs">The two keys Johnny uses itself (panel and nightly backup) always have access and are not listed.</p>

@elseif ($tab === 'settings')
    {{-- ===== Settings ===== --}}
    @php
        $maxSize = $usage['max_size'] ?? null;
        $maxObjects = $usage['max_objects'] ?? null;
        $quotaUnit = $maxSize === null ? 'GB' : ($maxSize >= 1024 ** 4 ? 'TB' : ($maxSize >= 1024 ** 3 ? 'GB' : 'MB'));
        $quotaValue = $maxSize === null ? '' : rtrim(rtrim(number_format($maxSize / BucketSettingsController::SIZE_UNITS[$quotaUnit], 2, '.', ''), '0'), '.');
        $sizePercent = $maxSize ? min(100, ($usage['size'] ?? 0) / $maxSize * 100) : null;
        $objectsPercent = $maxObjects ? min(100, ($usage['objects'] ?? 0) / $maxObjects * 100) : null;
        $meterState = fn (?float $percent) => $percent === null ? '' : ($percent >= 92 ? 'is-danger' : ($percent >= 80 ? 'is-warn' : ''));
    @endphp

    @foreach ($settingsErrors as $settingsError)
        <div class="flash flash-err" role="alert"><span>{{ $settingsError }}</span></div>
    @endforeach

    <section class="card">
        <h2>Usage and quotas</h2>
        <p class="card-sub">A quota is a ceiling for this bucket: Garage refuses uploads that would go over it. Leave a field empty for no limit.</p>

        @if ($usage['known'] ?? false)
            <div class="grid-2">
                <div>
                    <div class="meter-caption mb-1"><strong>{{ Format::bytes($usage['size'] ?? 0) }}</strong><span>{{ $maxSize ? 'of '.Format::bytes($maxSize) : 'no size limit' }}</span></div>
                    @if ($sizePercent !== null)
                        <span class="meter {{ $meterState($sizePercent) }}" role="meter" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ round($sizePercent) }}" aria-label="Size quota used"><span class="meter-fill" style="width: {{ round($sizePercent, 1) }}%"></span></span>
                    @endif
                </div>
                <div>
                    <div class="meter-caption mb-1"><strong>{{ number_format($usage['objects'] ?? 0) }} objects</strong><span>{{ $maxObjects ? 'of '.number_format($maxObjects) : 'no object limit' }}</span></div>
                    @if ($objectsPercent !== null)
                        <span class="meter {{ $meterState($objectsPercent) }}" role="meter" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ round($objectsPercent) }}" aria-label="Object quota used"><span class="meter-fill" style="width: {{ round($objectsPercent, 1) }}%"></span></span>
                    @endif
                </div>
            </div>
        @else
            <p class="notice mb-1">The usage of this bucket could not be read from Garage (<code>johnny bucket info</code>).</p>
        @endif

        <form method="POST" action="{{ route('buckets.quotas', $bucket) }}" class="mt-3">
            @csrf
            <div class="form-grid">
                <div class="field">
                    <label for="max_size">Largest size</label>
                    <div class="input-group">
                        <input type="number" id="max_size" name="max_size" value="{{ old('max_size', $quotaValue) }}" min="0" step="any" placeholder="No limit" inputmode="decimal">
                        <select name="max_size_unit" aria-label="Unit">
                            @foreach (array_keys(BucketSettingsController::SIZE_UNITS) as $unit)
                                <option value="{{ $unit }}" @selected(old('max_size_unit', $quotaUnit) === $unit)>{{ $unit }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="field">
                    <label for="max_objects">Most objects</label>
                    <input type="number" id="max_objects" name="max_objects" value="{{ old('max_objects', $maxObjects) }}" min="1" step="1" placeholder="No limit" inputmode="numeric">
                </div>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Save quotas</button>
            </div>
        </form>
    </section>

    <section class="card">
        <h2>Expiry</h2>
        <p class="card-sub">Let Garage delete old objects on its own: logs, exports, temporary files. Rules are applied once a day, so an object can outlive its limit by a few hours.</p>
        @if (($lifecycle['other_rules'] ?? 0) > 0)
            <p class="notice notice-warn mb-1">This bucket has {{ $lifecycle['other_rules'] }} more lifecycle {{ Str::plural('rule', $lifecycle['other_rules']) }} written by another tool. Saving here keeps them.</p>
        @endif
        <form method="POST" action="{{ route('buckets.lifecycle', $bucket) }}">
            @csrf
            <div class="form-grid">
                <div class="field">
                    <label for="expire_days">Delete objects after (days)</label>
                    <input type="number" id="expire_days" name="expire_days" value="{{ old('expire_days', $lifecycle['expire_days'] ?? '') }}" min="1" step="1" placeholder="Never" inputmode="numeric">
                </div>
                <div class="field">
                    <label for="expire_prefix">Only in this folder</label>
                    <input type="text" id="expire_prefix" name="expire_prefix" class="input-mono" value="{{ old('expire_prefix', $lifecycle['expire_prefix'] ?? '') }}" placeholder="The whole bucket" maxlength="1024">
                </div>
                <div class="field field-wide">
                    <label for="abort_days">Clean up unfinished uploads after (days)</label>
                    <input type="number" id="abort_days" name="abort_days" class="input-xs" value="{{ old('abort_days', $lifecycle['abort_days'] ?? '') }}" min="1" step="1" placeholder="Never" inputmode="numeric">
                    <p class="field-hint">Large uploads go up in parts. An upload that never finishes leaves its parts behind, using disk space without showing up as a file.</p>
                </div>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Save expiry rules</button>
            </div>
        </form>
    </section>

    <section class="card">
        <h2>Browser access (CORS)</h2>
        <p class="card-sub">Needed only when a web page loads or uploads files straight from the browser, for example with presigned URLs. Server-side clients ignore it.</p>
        @if (($cors['rules'] ?? 0) > 1)
            <p class="notice notice-warn mb-1">This bucket has {{ $cors['rules'] }} CORS rules written by another tool; the first is shown. Saving here replaces them all with one rule.</p>
        @endif
        <form method="POST" action="{{ route('buckets.cors', $bucket) }}">
            @csrf
            <div class="field">
                <label for="origins">Websites allowed</label>
                <textarea id="origins" name="origins" class="input-mono" rows="3" placeholder="https://app.example.com">{{ old('origins', implode("\n", $cors['origins'] ?? [])) }}</textarea>
                <p class="field-hint">One origin per line, without a path. <code>*</code> allows any website. Empty removes the rule.</p>
            </div>
            <div class="field">
                <span class="field-label">Methods</span>
                <fieldset class="perm-toggles">
                    <legend class="sr-only">Methods</legend>
                    @foreach (BucketSettingsController::CORS_METHODS as $method)
                        <label class="perm-toggle"><input type="checkbox" name="methods[]" value="{{ $method }}" @checked(in_array($method, old('methods', ($cors['methods'] ?? []) ?: ['GET', 'HEAD']), true))> {{ $method }}</label>
                    @endforeach
                </fieldset>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Save CORS rule</button>
            </div>
        </form>
    </section>

    <section class="card">
        <h2>Delete this bucket</h2>
        @if ($isProtected)
            <p class="muted">The <code>{{ $bucket }}</code> bucket holds application data and cannot be deleted.</p>
        @else
            <p class="card-sub">A bucket can be deleted once it is empty. Its keys keep existing; they only lose this bucket.</p>
            <form method="POST" action="{{ route('buckets.destroy', $bucket) }}" class="inline-form" data-confirm="Delete bucket &quot;{{ $bucket }}&quot;? It must be empty.">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-danger">Delete bucket</button>
            </form>
        @endif
    </section>

@else
    {{-- ===== Connect ===== --}}
    <section class="card">
        <h2>Connection details</h2>
        <p class="card-sub">Johnny speaks the S3 API: any S3 client works with these three values and an access key that has permissions on this bucket.</p>
        <div class="copy-fields">
            <div class="copy-field">
                <span class="copy-label">Endpoint</span>
                <code id="connect-endpoint">{{ $endpoint }}</code>
                <button type="button" class="btn btn-sm" data-copy-from="#connect-endpoint">Copy</button>
            </div>
            <div class="copy-field">
                <span class="copy-label">Region</span>
                <code id="connect-region">{{ $region }}</code>
                <button type="button" class="btn btn-sm" data-copy-from="#connect-region">Copy</button>
            </div>
            <div class="copy-field">
                <span class="copy-label">Bucket</span>
                <code id="connect-bucket">{{ $bucket }}</code>
                <button type="button" class="btn btn-sm" data-copy-from="#connect-bucket">Copy</button>
            </div>
        </div>
        <p class="field-hint mt-2">Use path-style addressing (<code>{{ rtrim($endpoint, '/') }}/{{ $bucket }}/…</code>). Secrets are shown once, when a key is created: <a href="{{ route('keys.index') }}#create">create a key</a>, then grant it access in the Access keys tab.</p>
    </section>

    <section class="card">
        <h2>Configuration for your client</h2>
        @include('partials.connect-snippets', ['keyId' => 'YOUR_ACCESS_KEY_ID', 'secret' => 'YOUR_SECRET_ACCESS_KEY'])
    </section>
@endif
@endsection
