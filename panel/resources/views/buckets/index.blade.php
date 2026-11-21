@extends('layouts.app')

@use('App\Support\Format')

@section('title', 'Buckets — '.config('app.name'))

@section('crumbs')
    <span>Buckets</span>
@endsection

@section('content')
<div class="page-head">
    <div>
        <h1 class="page-title">Buckets</h1>
        <p class="page-sub">Each bucket is a separate space for files. New buckets are included in the nightly backup automatically.</p>
    </div>
    <div class="page-actions">
        <a href="{{ route('transfers.index') }}" class="btn">Import from another provider</a>
    </div>
</div>

@if (! empty($error))
    <div class="flash flash-err" role="alert"><span>{{ $error }}</span></div>
@endif

<section class="card" id="create">
    <h2>Create a bucket</h2>
    <form method="POST" action="{{ route('buckets.store') }}" class="form-row">
        @csrf
        <input type="text" name="name" class="input-mono" value="{{ old('name') }}" placeholder="bucket-name" required minlength="2" maxlength="255" pattern="[a-z0-9][a-z0-9._\-]+" title="Lowercase letters, digits, dot, dash and underscore; at least 2 characters" aria-label="Bucket name">
        <button type="submit" class="btn btn-primary">Create bucket</button>
    </form>
    <p class="field-hint">Lowercase letters, digits, dots and dashes. A bucket cannot be renamed later.</p>
</section>

<section class="card card-flush">
    <div class="card-head">
        <h2>All buckets <span class="count">{{ count($buckets) }}</span></h2>
        @if (count($buckets) > 6)
            <input type="search" class="input-sm" placeholder="Filter by name" aria-label="Filter buckets" data-filter-table="#bucket-table">
        @endif
    </div>
    @if (empty($buckets))
        <div class="empty">
            <h3>No buckets yet</h3>
            <p>Create the first one above, or import one from another S3 provider.</p>
        </div>
    @else
        <div class="table-wrap">
        <table id="bucket-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Created</th>
                    <th class="col-actions"><span class="sr-only">Actions</span></th>
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
                        @if (in_array($b['name'], $protectedBuckets, true))
                            <span class="badge badge-muted" title="The default bucket holds application data and cannot be deleted">protected</span>
                        @endif
                    </td>
                    <td class="muted" title="{{ $b['created'] }}">{{ Format::date($b['created']) }}</td>
                    <td class="col-actions">
                        <div class="actions">
                            <a href="{{ route('buckets.show', ['bucket' => $b['name'], 'tab' => 'settings']) }}" class="btn btn-ghost btn-sm">Settings</a>
                            @unless (in_array($b['name'], $protectedBuckets, true))
                                <form method="POST" action="{{ route('buckets.destroy', $b['name']) }}" class="inline-form" data-confirm="Delete bucket &quot;{{ $b['name'] }}&quot;? It must be empty.">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                </form>
                            @endunless
                        </div>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
        </div>
    @endif
</section>
@endsection
