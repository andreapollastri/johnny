@extends('layouts.app')

@use('App\Support\Format')

@section('title', 'Buckets — '.config('app.name'))

@section('content')
<div class="page-header">
    <h1>Buckets</h1>
    <p class="subtitle">Create and manage your Garage S3 buckets. New buckets are readable by the nightly backup automatically.</p>
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
        <input type="text" name="name" value="{{ old('name') }}" placeholder="bucket-name" required minlength="2" maxlength="255" pattern="[a-z0-9][a-z0-9._\-]+" title="Lowercase letters, digits, dot, dash and underscore; at least 2 characters" aria-label="Bucket name">
        <button type="submit">Create bucket</button>
    </form>
</div>

<div class="card">
    <div class="card-head">
        <h2>All buckets <span class="count">{{ count($buckets) }}</span></h2>
        @if (count($buckets) > 6)
            <input type="search" class="input-sm" placeholder="Filter…" aria-label="Filter buckets" data-filter-table="#bucket-table">
        @endif
    </div>
    @if (empty($buckets))
        <div class="empty-state">
            <p>No buckets yet.</p>
        </div>
    @else
        <div class="table-wrap">
        <table id="bucket-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Created</th>
                    <th class="col-actions"></th>
                </tr>
            </thead>
            <tbody>
            @foreach ($buckets as $b)
                <tr data-filter-value="{{ $b['name'] }}">
                    <td>
                        <a href="{{ route('buckets.show', $b['name']) }}" class="fm-entry fm-folder">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
                            {{ $b['name'] }}
                        </a>
                    </td>
                    <td class="muted" title="{{ $b['created'] }}">{{ Format::date($b['created']) }}</td>
                    <td class="col-actions">
                        @if (in_array($b['name'], $protectedBuckets, true))
                            <span class="badge badge-muted" title="The default bucket holds application data and cannot be deleted">protected</span>
                        @else
                            <form method="POST" action="{{ route('buckets.destroy', $b['name']) }}" data-confirm="Delete bucket &quot;{{ $b['name'] }}&quot;? It must be empty.">
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
        </div>
    @endif
</div>
@endsection

@push('scripts')
<script>
document.querySelectorAll('[data-filter-table]').forEach(function (input) {
    var rows = document.querySelectorAll(input.getAttribute('data-filter-table') + ' tbody tr');
    input.addEventListener('input', function () {
        var q = input.value.trim().toLowerCase();
        rows.forEach(function (row) {
            row.hidden = q !== '' && row.getAttribute('data-filter-value').toLowerCase().indexOf(q) === -1;
        });
    });
});
</script>
@endpush
