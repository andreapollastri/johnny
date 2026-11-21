{{-- The credentials of a key that was just created, shown once. Needs $newKey (session "new_key"). --}}
<section class="card card-highlight">
    <h2>New key: {{ $newKey['name'] }}</h2>
    <p class="card-sub">Copy the secret now. Garage stores it, but this panel never shows it again.</p>
    @if ($newKey['credentials'])
        <div class="copy-fields">
            <div class="copy-field">
                <span class="copy-label">Access key ID</span>
                <code id="new-key-id">{{ $newKey['credentials']['access_key_id'] }}</code>
                <button type="button" class="btn btn-sm" data-copy-from="#new-key-id">Copy</button>
            </div>
            <div class="copy-field">
                <span class="copy-label">Secret access key</span>
                <code id="new-key-secret">{{ $newKey['credentials']['secret_access_key'] }}</code>
                <button type="button" class="btn btn-sm" data-copy-from="#new-key-secret">Copy</button>
            </div>
            <div class="copy-field">
                <span class="copy-label">Endpoint</span>
                <code id="new-key-endpoint">{{ config('services.garage.endpoint') }}</code>
                <button type="button" class="btn btn-sm" data-copy-from="#new-key-endpoint">Copy</button>
            </div>
        </div>
        <h3 class="mt-3 mb-1">Ready to paste</h3>
        @include('partials.connect-snippets', [
            'keyId' => $newKey['credentials']['access_key_id'],
            'secret' => $newKey['credentials']['secret_access_key'],
            'bucket' => $newKey['bucket'] ?? 'your-bucket',
            'endpoint' => (string) config('services.garage.endpoint'),
            'region' => (string) config('services.garage.region', 'johnny'),
        ])
    @else
        <pre class="raw">{{ $newKey['raw'] }}</pre>
    @endif
</section>
