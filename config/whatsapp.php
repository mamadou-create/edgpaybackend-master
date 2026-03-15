<?php

return [
    'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
    'access_token' => env('WHATSAPP_ACCESS_TOKEN', config('services.whatsapp.access_token')),
    'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID', config('services.whatsapp.phone_number_id')),
    'graph_url' => env('WHATSAPP_GRAPH_URL', config('services.whatsapp.graph_url', 'https://graph.facebook.com/v21.0')),
    'app_secret' => env('WHATSAPP_APP_SECRET', config('services.whatsapp.app_secret')),
    'validate_signature' => filter_var(env('WHATSAPP_VALIDATE_SIGNATURE', false), FILTER_VALIDATE_BOOLEAN),
    'queue_outbound' => filter_var(env('WHATSAPP_QUEUE_OUTBOUND', true), FILTER_VALIDATE_BOOLEAN),
    'outbound_queue' => env('WHATSAPP_OUTBOUND_QUEUE', 'whatsapp'),
    'default_country_code' => env('WHATSAPP_DEFAULT_COUNTRY_CODE', '224'),
    'session_ttl_minutes' => (int) env('WHATSAPP_SESSION_TTL_MINUTES', 30),
    'transaction_limit' => (int) env('WHATSAPP_TRANSACTION_LIMIT', 5000000),
    'otp_threshold' => (int) env('WHATSAPP_OTP_THRESHOLD', 100000),
    'default_currency' => env('WHATSAPP_DEFAULT_CURRENCY', 'GNF'),
];
