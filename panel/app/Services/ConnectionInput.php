<?php

namespace App\Services;

use App\Models\RemoteConnection;
use App\Support\StorageProviders;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The fields of a connection to another S3 provider or to an SFTP server, validated the same way
 * for the panel's form and for the API.
 */
final class ConnectionInput
{
    /**
     * @return array<string, mixed>
     */
    public static function validate(Request $request, ?RemoteConnection $connection = null): array
    {
        if (StorageProviders::isSftp((string) $request->input('provider'))) {
            return self::sftp($request, $connection);
        }

        $provider = StorageProviders::get((string) $request->input('provider'));

        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:64', 'regex:/^[A-Za-z0-9][A-Za-z0-9 ._-]*$/',
                Rule::unique('remote_connections', 'name')->ignore($connection),
            ],
            'provider' => ['required', Rule::in(StorageProviders::ids())],
            'endpoint' => [Rule::requiredIf($provider['needs_endpoint']), 'nullable', 'url:http,https', 'max:255'],
            'region' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9-]+$/'],
            'access_key_id' => ['required', 'string', 'max:255'],
            'secret_access_key' => [$connection === null ? 'required' : 'nullable', 'string', 'max:1024'],
            'path_style' => ['nullable', 'boolean'],
        ], [
            'name.regex' => 'Use letters, digits, spaces, dots, dashes and underscores.',
            'endpoint.required' => 'This provider needs an endpoint URL.',
        ]);

        $data['endpoint'] = isset($data['endpoint']) ? rtrim($data['endpoint'], '/') : null;
        $data['path_style'] = $request->boolean('path_style');

        // The secret is never sent back: an empty field on an update keeps the stored one.
        if ($connection !== null && ($data['secret_access_key'] ?? null) === null) {
            unset($data['secret_access_key']);
        }

        return $data;
    }

    /**
     * An SFTP server: host, port and user name, then a password or a private key. They are kept
     * in the same columns as an S3 account (see RemoteConnection::isSftp).
     *
     * @return array<string, mixed>
     */
    private static function sftp(Request $request, ?RemoteConnection $connection): array
    {
        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:64', 'regex:/^[A-Za-z0-9][A-Za-z0-9 ._-]*$/',
                Rule::unique('remote_connections', 'name')->ignore($connection),
            ],
            'provider' => ['required', Rule::in(StorageProviders::ids())],
            'host' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$/'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'username' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._@-]+$/'],
            'password' => ['nullable', 'string', 'max:1024'],
            'private_key' => ['nullable', 'string', 'max:16384', 'starts_with:-----BEGIN'],
        ], [
            'name.regex' => 'Use letters, digits, spaces, dots, dashes and underscores.',
            'host.regex' => 'Type a host name or an IP address, without sftp:// and without a path.',
            'private_key.starts_with' => 'Paste the whole private key, from its -----BEGIN line.',
        ]);

        $secret = $data['private_key'] ?? $data['password'] ?? null;
        $keepsSecret = $connection !== null && $connection->isSftp();

        if ($secret === null && ! $keepsSecret) {
            throw ValidationException::withMessages(['password' => 'Type a password or paste a private key.']);
        }

        $attributes = [
            'name' => $data['name'],
            'provider' => $data['provider'],
            'endpoint' => 'sftp://'.strtolower($data['host']).':'.(int) ($data['port'] ?? 22),
            'region' => null,
            'access_key_id' => $data['username'],
            'path_style' => false,
            // Saving the connection again is how a reinstalled server is accepted: its host keys
            // are pinned afresh by the check that follows.
            'host_key' => null,
        ];

        if ($secret !== null) {
            $attributes['secret_access_key'] = trim($secret);
        }

        return $attributes;
    }
}
