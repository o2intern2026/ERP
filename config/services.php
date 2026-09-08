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

    // Transdirect carrier API (contracts/carriers.md, B5e). Key lives in .env / the team vault only — never in the repo.
    'transdirect' => [
        'api_key' => env('TRANSDIRECT_API_KEY'),
        'base_url' => env('TRANSDIRECT_BASE_URL', 'https://www.transdirect.com.au/api'),
    ],

    // Karrio — open-source, self-hosted multi-carrier shipping API (docker/karrio). Project lead 2026-09-08: use it instead
    // of Transdirect for now. Key only in .env / vault. carrier_ids = optional comma list of Karrio connection ids to quote.
    'karrio' => [
        'api_key' => env('KARRIO_API_KEY'),
        'base_url' => env('KARRIO_BASE_URL', 'http://localhost:5002'),
        'carrier_ids' => env('KARRIO_CARRIER_IDS', ''),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
