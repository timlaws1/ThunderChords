<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    'musicbrainz' => [
        'base_url' => 'https://musicbrainz.org/ws/2',
        'user_agent' => env('MUSICBRAINZ_USER_AGENT', 'ThunderChords/1.0 ('.env('APP_URL', 'http://localhost').')'),
    ],

    'itunes' => [
        'base_url' => 'https://itunes.apple.com',
    ],

    'lastfm' => [
        'key' => env('LASTFM_API_KEY'),
        'base_url' => 'https://ws.audioscrobbler.com/2.0/',
    ],

];
