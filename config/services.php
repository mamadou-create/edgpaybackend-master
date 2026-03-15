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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
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

    'dml' => [
        'base_url' => env('DML_BASE_URL'),
        'verify_ssl' => filter_var(env('DML_VERIFY_SSL', true), FILTER_VALIDATE_BOOLEAN),
        'token' => env('DML_TOKEN'),
        'login_phone' => env('DML_LOGIN_PHONE'),
        'login_password' => env('DML_LOGIN_PASSWORD'),
    ],

    'djomy' => [
        'base_url' => env('DJOMY_BASE_URL'),
        'api_key' => env('DJOMY_API_KEY'),
        'client_id' => env('DJOMY_CLIENT_ID'),
        'client_secret' => env('DJOMY_CLIENT_SECRET'),
        'domain' => env('DJOMY_PARTNER_DOMAIN'),
        'return_url' => env('DJOMY_RETURN_URL'),
        'cancel_url' => env('DJOMY_CANCEL_URL'),
    ],

    'nimba' => [
        'base_url' => env('NIMBA_BASE_URL', 'https://api.nimbasms.com/v1'),
        'basic_auth' => env('NIMBA_BASIC_AUTH'),
        'sid' => env('NIMBA_SID'),
        'secret_token' => env('NIMBA_SECRET_TOKEN'),
    ],

    'whatsapp' => [
        'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'graph_url' => env('WHATSAPP_GRAPH_URL', 'https://graph.facebook.com/v21.0'),
        'app_secret' => env('WHATSAPP_APP_SECRET'),
    ],

];
