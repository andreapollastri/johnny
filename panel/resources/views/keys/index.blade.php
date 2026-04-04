@extends('layouts.app')

@section('title', 'API Keys — '.config('app.name'))

@section('content')
<div class="page-header">
    <h1>API Keys</h1>
    <p class="subtitle">Create and delete API keys. To grant bucket access, open the bucket and use the Keys tab.</p>
</div>

@if (session('status'))
    <div class="status">{{ session('status') }}</div>
@endif
@if ($errors->any())
    <div class="errors">{{ $errors->first() }}</div>
@endif

@if (session('key_create_output'))
<div class="card">
    <h2>New key created</h2>
    <p class="muted text-sm">Copy the secret now — it will not be shown again.</p>
    <pre class="raw">{{ session('key_create_output') }}</pre>
</div>
@endif

<div class="card">
    <h2>Create key</h2>
    <form method="POST" action="{{ route('keys.store') }}" class="form-row">
        @csrf
        <input type="text" name="name" value="{{ old('name') }}" placeholder="my-app-key" required>
        <button type="submit">Create key</button>
    </form>
</div>

@if (count($keys) > 0)
<div class="card">
    <h2>All keys</h2>
    <table>
        <thead>
            <tr>
                <th>Key ID</th>
                <th>Name</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        @foreach ($keys as $k)
            <tr>
                <td><code class="text-xs">{{ $k['id'] }}</code></td>
                <td>{{ $k['name'] }}</td>
                <td>
                    <form method="POST" action="{{ route('keys.destroy') }}" onsubmit="return confirm('Permanently delete key {{ $k['name'] }} ({{ $k['id'] }})?');" style="margin:0;">
                        @csrf
                        @method('DELETE')
                        <input type="hidden" name="key_id" value="{{ $k['id'] }}">
                        <button type="submit" class="danger sm">Delete</button>
                    </form>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@elseif ($error)
<div class="card">
    <h2>Key list</h2>
    <div class="errors">{{ $error }}</div>
</div>
@else
<div class="card">
    <div class="empty-state">
        <p>No custom keys yet. Create one above.</p>
    </div>
</div>
@endif
@endsection
