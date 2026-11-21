@extends('layouts.app')

@section('title', 'New transfer — '.config('app.name'))

@section('crumbs')
    <a href="{{ route('transfers.index') }}">Transfers</a>
    <span class="sep">/</span>
    <span>New transfer</span>
@endsection

@section('content')
@php
    $chosenDirection = old('direction', $direction);
    $chosenMode = old('mode', 'copy');
    $chosenSchedule = old('schedule', $schedule ?? 'once');
@endphp

<div class="page-head">
    <div>
        <h1 class="page-title">New transfer</h1>
        <p class="page-sub">Copy a whole bucket, or one folder of it, between Johnny and another provider or SFTP server: once, or kept in sync on a schedule. Nothing is deleted at the source.</p>
    </div>
</div>

<form method="POST" action="{{ route('transfers.store') }}" data-transfer-form data-form-url="{{ route('transfers.create') }}">
    @csrf

    <section class="card">
        <h2>Direction</h2>
        <fieldset class="seg">
            <legend class="sr-only">Direction</legend>
            <label class="seg-option">
                <input type="radio" name="direction" value="import" @checked($chosenDirection === 'import')>
                <strong>In: import into Johnny</strong>
                <span>From a bucket at the other provider to a bucket here.</span>
            </label>
            <label class="seg-option">
                <input type="radio" name="direction" value="export" @checked($chosenDirection === 'export')>
                <strong>Out: export from Johnny</strong>
                <span>From a bucket here to a bucket at the other provider.</span>
            </label>
        </fieldset>
    </section>

    <section class="card">
        <h2>Buckets</h2>
        <div class="form-grid">
            <div class="field field-wide">
                <label for="connection_id">Connection</label>
                <select id="connection_id" name="connection_id" required>
                    @foreach ($connections as $option)
                        <option value="{{ $option->id }}" @selected((int) old('connection_id', $connection->id) === $option->id)>{{ $option->name }} — {{ $option->providerLabel() }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="remote_bucket">{{ ucfirst($connection->containerWord()) }} at {{ $connection->name }}</label>
                <input type="text" id="remote_bucket" name="remote_bucket" class="input-mono" value="{{ old('remote_bucket', $remoteBucket) }}" list="remote-buckets" required maxlength="255" autocomplete="off" spellcheck="false" placeholder="{{ $connection->isSftp() ? 'backups/media' : '' }}">
                <datalist id="remote-buckets">
                    @foreach ($remoteBuckets as $name)
                        <option value="{{ $name }}"></option>
                    @endforeach
                </datalist>
                @if ($connection->isSftp())
                    <p class="field-hint">A path on the server, for example <code>backups/media</code> (inside the folder the user lands in) or <code>/srv/backups/media</code>. A folder that does not exist is created.</p>
                @else
                    <p class="field-hint" data-when-direction="import">The bucket to read from.</p>
                    <p class="field-hint" data-when-direction="export" hidden>The bucket to write to. It must exist, unless you let Johnny create it below.</p>
                @endif
            </div>
            <div class="field">
                <label for="local_bucket">Bucket on Johnny</label>
                <input type="text" id="local_bucket" name="local_bucket" class="input-mono" value="{{ old('local_bucket', $localBucket) }}" list="local-buckets" required maxlength="255" autocomplete="off" spellcheck="false">
                <datalist id="local-buckets">
                    @foreach ($localBuckets as $name)
                        <option value="{{ $name }}"></option>
                    @endforeach
                </datalist>
                <p class="field-hint" data-when-direction="import">An existing bucket, or a new name: Johnny creates it.</p>
                <p class="field-hint" data-when-direction="export" hidden>The bucket to read from.</p>
            </div>
            <div class="field field-wide">
                <label for="prefix">Only this folder</label>
                <input type="text" id="prefix" name="prefix" class="input-mono" value="{{ old('prefix') }}" placeholder="The whole bucket" maxlength="1024" autocomplete="off" spellcheck="false">
                <p class="field-hint">Optional, for example <code>uploads/2026/</code>. Files keep the same path at the destination.</p>
            </div>
        </div>
    </section>

    <section class="card">
        <h2>How to copy</h2>
        <fieldset class="seg">
            <legend class="sr-only">How to copy</legend>
            <label class="seg-option">
                <input type="radio" name="mode" value="copy" @checked($chosenMode === 'copy')>
                <strong>Copy</strong>
                <span>Add new and changed files. Nothing at the destination is deleted.</span>
            </label>
            <label class="seg-option">
                <input type="radio" name="mode" value="sync" @checked($chosenMode === 'sync')>
                <strong>Mirror</strong>
                <span>Make the destination identical to the source, deleting what is not there.</span>
            </label>
        </fieldset>
        <p class="notice notice-warn" data-when-mode="sync" hidden>Mirror deletes every file at the destination that the source does not have. Run it as a dry run first.</p>

        <div class="field mt-3" data-show-if="schedule=once">
            <label class="check">
                <input type="checkbox" name="dry_run" value="1" @checked(old('dry_run'))>
                <span>Dry run <small>List what would be copied or deleted, without changing anything.</small></span>
            </label>
        </div>
        @unless ($connection->isSftp())
        <div class="field" data-when-direction="export" hidden>
            <label class="check">
                <input type="checkbox" name="create_bucket" value="1" @checked(old('create_bucket'))>
                <span>Create the bucket at the provider if it does not exist <small>The access key needs the right to create buckets.</small></span>
            </label>
        </div>
        @endunless
    </section>

    <section class="card">
        <h2>When</h2>
        <fieldset class="seg">
            <legend class="sr-only">When</legend>
            <label class="seg-option">
                <input type="radio" name="schedule" value="once" @checked($chosenSchedule !== 'repeat')>
                <strong>Once, now</strong>
                <span>One transfer that starts as soon as you confirm.</span>
            </label>
            <label class="seg-option">
                <input type="radio" name="schedule" value="repeat" @checked($chosenSchedule === 'repeat')>
                <strong>Keep in sync</strong>
                <span>Starts now, then repeats on its own. Each run only moves what changed.</span>
            </label>
        </fieldset>
        <div class="form-grid mt-3" data-show-if="schedule=repeat" @if ($chosenSchedule !== 'repeat') hidden @endif>
            @include('partials.interval-fields')
            <p class="field-hint field-wide">A run that is still going when the next one is due is not doubled: the next starts when it ends. Pause, change or delete the sync at any time from Transfers.</p>
        </div>
    </section>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary"><span data-show-if="schedule=once">Start transfer</span><span data-show-if="schedule=repeat" hidden>Create sync</span></button>
        <a href="{{ route('transfers.index') }}" class="btn btn-ghost">Cancel</a>
    </div>
</form>

@push('scripts')
    <script src="{{ asset('js/transfers.js') }}?v={{ filemtime(public_path('js/transfers.js')) }}" defer></script>
@endpush
@endsection
