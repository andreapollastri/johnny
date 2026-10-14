<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'garage' => [
        'key' => env('GARAGE_ACCESS_KEY_ID'),
        'secret' => env('GARAGE_SECRET_ACCESS_KEY'),
        'region' => env('GARAGE_DEFAULT_REGION', 'johnny'),
        'endpoint' => env('GARAGE_ENDPOINT'),
        'key_name' => env('GARAGE_KEY_NAME', 'johnny-default'),
        // Filesystem holding Garage data blocks; used for the dashboard disk gauge.
        'data_dir' => env('GARAGE_DATA_DIR', '/var/lib/johnny/data'),
    ],

    'johnny' => [
        // The panel shells out to the johnny CLI (Garage passthrough) as the "johnny" system user.
        // Leave JOHNNY_CLI_SUDO_USER empty to call the binary directly (local demo, containers).
        'binary' => env('JOHNNY_CLI_BINARY', '/usr/local/bin/johnny'),
        'sudo_user' => env('JOHNNY_CLI_SUDO_USER', 'johnny'),

        // Updates: the root updater (scripts/johnny-update) keeps its state in a JSON file the panel
        // reads; checking, starting and switching go through `sudo -n johnny update …` as root
        // (the exact command lines in /etc/sudoers.d/johnny-panel).
        'updates' => [
            'state' => env('JOHNNY_UPDATE_STATE', '/var/lib/johnny-update/state.json'),
            'sudo' => (bool) env('JOHNNY_UPDATE_SUDO', true),
            'version_file' => env('JOHNNY_VERSION_FILE', base_path('../VERSION')),
            'repository' => env('JOHNNY_REPOSITORY_URL', 'https://github.com/andreapollastri/johnny'),
        ],
    ],

];
