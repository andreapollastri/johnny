{{-- The fields of a connection to another S3 provider or to an SFTP server. $connection is null when adding one. --}}
@php
    $editing = isset($connection) && $connection !== null;
    $isSftp = old('provider', $editing ? $connection->provider : 'aws') === 'sftp';
    $s3Providers = implode(',', array_diff(array_keys($providers), ['sftp']));
@endphp
<div class="form-grid">
    <div class="field">
        <label for="conn-name">Name</label>
        <input type="text" id="conn-name" name="name" value="{{ old('name', $editing ? $connection->name : '') }}" placeholder="Spaces production" required maxlength="64">
    </div>
    <div class="field">
        <label for="conn-provider">Provider</label>
        <select id="conn-provider" name="provider" required>
            @foreach ($providers as $id => $provider)
                <option value="{{ $id }}" @selected(old('provider', $editing ? $connection->provider : 'aws') === $id)>{{ $provider['label'] }}</option>
            @endforeach
        </select>
    </div>
    <div class="field field-wide" data-show-if="provider={{ $s3Providers }}" @if ($isSftp) hidden @endif>
        <label for="conn-endpoint">Endpoint</label>
        <input type="url" id="conn-endpoint" name="endpoint" class="input-mono" value="{{ old('endpoint', $editing && ! $isSftp ? $connection->endpoint : '') }}" maxlength="255" autocomplete="off" spellcheck="false">
        <p class="field-hint" data-endpoint-hint>The S3 URL of the provider, without the bucket name.</p>
    </div>
    <div class="field" data-show-if="provider={{ $s3Providers }}" @if ($isSftp) hidden @endif>
        <label for="conn-key">Access key ID</label>
        <input type="text" id="conn-key" name="access_key_id" class="input-mono" value="{{ old('access_key_id', $editing && ! $isSftp ? $connection->access_key_id : '') }}" required maxlength="255" autocomplete="off" spellcheck="false">
    </div>
    <div class="field" data-show-if="provider={{ $s3Providers }}" @if ($isSftp) hidden @endif>
        <label for="conn-secret">Secret access key</label>
        <input type="password" id="conn-secret" name="secret_access_key" class="input-mono" @required(! $editing || $isSftp) maxlength="1024" autocomplete="new-password" placeholder="{{ $editing && ! $isSftp ? 'Unchanged' : '' }}">
    </div>
    <div class="field" data-show-if="provider={{ $s3Providers }}" @if ($isSftp) hidden @endif>
        <label for="conn-region">Region</label>
        <input type="text" id="conn-region" name="region" class="input-mono" value="{{ old('region', $editing ? $connection->region : '') }}" maxlength="64" autocomplete="off" spellcheck="false">
    </div>
    <div class="field" data-show-if="provider={{ $s3Providers }}" @if ($isSftp) hidden @endif>
        <span class="field-label">Addressing</span>
        <label class="check">
            <input type="checkbox" name="path_style" value="1" @checked(old('path_style', $editing ? $connection->path_style : false))>
            <span>Path-style URLs <small>endpoint/bucket/key — right for most providers other than AWS</small></span>
        </label>
    </div>
    <div class="field" data-show-if="provider=sftp" @unless ($isSftp) hidden @endunless>
        <label for="conn-host">Host</label>
        <input type="text" id="conn-host" name="host" class="input-mono" value="{{ old('host', $editing && $isSftp ? $connection->sftpHost() : '') }}" placeholder="backup.example.com" required maxlength="255" autocomplete="off" spellcheck="false">
    </div>
    <div class="field" data-show-if="provider=sftp" @unless ($isSftp) hidden @endunless>
        <label for="conn-port">Port</label>
        <input type="number" id="conn-port" name="port" class="input-xs" value="{{ old('port', $editing && $isSftp ? $connection->sftpPort() : 22) }}" min="1" max="65535" inputmode="numeric">
    </div>
    <div class="field" data-show-if="provider=sftp" @unless ($isSftp) hidden @endunless>
        <label for="conn-user">User name</label>
        <input type="text" id="conn-user" name="username" class="input-mono" value="{{ old('username', $editing && $isSftp ? $connection->access_key_id : '') }}" required maxlength="64" autocomplete="off" spellcheck="false">
    </div>
    <div class="field" data-show-if="provider=sftp" @unless ($isSftp) hidden @endunless>
        <label for="conn-password">Password</label>
        <input type="password" id="conn-password" name="password" class="input-mono" maxlength="1024" autocomplete="new-password" placeholder="{{ $editing && $isSftp ? 'Unchanged' : 'Or a private key, below' }}">
    </div>
    <div class="field field-wide" data-show-if="provider=sftp" @unless ($isSftp) hidden @endunless>
        <label for="conn-private-key">Private key</label>
        <textarea id="conn-private-key" name="private_key" class="input-mono" rows="4" maxlength="16384" autocomplete="off" spellcheck="false" placeholder="-----BEGIN OPENSSH PRIVATE KEY-----">{{ old('private_key') }}</textarea>
        <p class="field-hint">Instead of a password: a key without a passphrase, whose public half is in the user's <code>authorized_keys</code>. The server's host key is remembered the first time it answers.</p>
    </div>
</div>
<p class="notice" data-provider-note></p>
<p class="field-hint mt-2">Secrets, passwords and keys are stored encrypted in the panel's database and are only used on this server, by rclone, while a transfer runs.</p>
