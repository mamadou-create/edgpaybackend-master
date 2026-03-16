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

    'nimba_ai' => [
        'enabled' => filter_var(env('NIMBA_AI_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'provider' => env('NIMBA_AI_PROVIDER', 'chatgpt'),
        'base_url' => env('NIMBA_AI_BASE_URL', 'https://api.openai.com/v1/chat/completions'),
        'api_key' => env('NIMBA_AI_API_KEY'),
        'model' => env('NIMBA_AI_MODEL', 'gpt-4.1-mini'),
        // OpenAI example: https://api.openai.com/v1/chat/completions
        // Gemini example: https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent
        // Claude example: https://api.anthropic.com/v1/messages
        'timeout' => (int) env('NIMBA_AI_TIMEOUT', 20),
        'temperature' => (float) env('NIMBA_AI_TEMPERATURE', 0.3),
        'max_tokens' => (int) env('NIMBA_AI_MAX_TOKENS', 300),
        'organization' => env('NIMBA_AI_ORGANIZATION'),
        'project' => env('NIMBA_AI_PROJECT'),
        'providers' => [
            'chatgpt' => [
                'base_url' => env('NIMBA_AI_OPENAI_BASE_URL', env('NIMBA_AI_BASE_URL', 'https://api.openai.com/v1/chat/completions')),
                'api_key' => env('NIMBA_AI_OPENAI_API_KEY', env('NIMBA_AI_API_KEY')),
                'model' => env('NIMBA_AI_OPENAI_MODEL', env('NIMBA_AI_MODEL', 'gpt-4.1-mini')),
                'organization' => env('NIMBA_AI_OPENAI_ORGANIZATION', env('NIMBA_AI_ORGANIZATION')),
                'project' => env('NIMBA_AI_OPENAI_PROJECT', env('NIMBA_AI_PROJECT')),
            ],
            'gemini' => [
                'base_url' => env('NIMBA_AI_GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent'),
                'api_key' => env('NIMBA_AI_GEMINI_API_KEY', env('NIMBA_AI_API_KEY')),
                'model' => env('NIMBA_AI_GEMINI_MODEL', 'gemini-2.0-flash'),
            ],
            'claude' => [
                'base_url' => env('NIMBA_AI_CLAUDE_BASE_URL', 'https://api.anthropic.com/v1/messages'),
                'api_key' => env('NIMBA_AI_CLAUDE_API_KEY', env('NIMBA_AI_API_KEY')),
                'model' => env('NIMBA_AI_CLAUDE_MODEL', 'claude-3-5-sonnet-20241022'),
                'version' => env('NIMBA_AI_CLAUDE_VERSION', '2023-06-01'),
            ],
        ],
        'enable_app_fallback' => filter_var(env('NIMBA_AI_ENABLE_APP_FALLBACK', true), FILTER_VALIDATE_BOOLEAN),
        'enable_whatsapp_fallback' => filter_var(env('NIMBA_AI_ENABLE_WHATSAPP_FALLBACK', true), FILTER_VALIDATE_BOOLEAN),
        'rag_enabled' => filter_var(env('NIMBA_AI_RAG_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'rag_max_snippets' => (int) env('NIMBA_AI_RAG_MAX_SNIPPETS', 4),
        'rag_min_score' => (float) env('NIMBA_AI_RAG_MIN_SCORE', 0.18),
        'web_search' => [
            'enabled' => filter_var(env('NIMBA_AI_WEB_SEARCH_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
            'provider' => env('NIMBA_AI_WEB_SEARCH_PROVIDER', 'serper'),
            'base_url' => env('NIMBA_AI_WEB_SEARCH_BASE_URL', 'https://google.serper.dev/search'),
            'api_key' => env('NIMBA_AI_WEB_SEARCH_API_KEY'),
            'timeout' => (int) env('NIMBA_AI_WEB_SEARCH_TIMEOUT', 12),
            'max_results' => (int) env('NIMBA_AI_WEB_SEARCH_MAX_RESULTS', 4),
            'language' => env('NIMBA_AI_WEB_SEARCH_LANGUAGE', 'fr'),
            'region' => env('NIMBA_AI_WEB_SEARCH_REGION', 'gn'),
            'providers' => [
                'serper' => [
                    'base_url' => env('NIMBA_AI_WEB_SEARCH_SERPER_BASE_URL', 'https://google.serper.dev/search'),
                    'api_key' => env('NIMBA_AI_WEB_SEARCH_SERPER_API_KEY', env('NIMBA_AI_WEB_SEARCH_API_KEY')),
                ],
                'tavily' => [
                    'base_url' => env('NIMBA_AI_WEB_SEARCH_TAVILY_BASE_URL', 'https://api.tavily.com/search'),
                    'api_key' => env('NIMBA_AI_WEB_SEARCH_TAVILY_API_KEY', env('NIMBA_AI_WEB_SEARCH_API_KEY')),
                ],
            ],
        ],
        'system_prompt' => env('NIMBA_AI_SYSTEM_PROMPT', 'Tu es NIMBA, assistant EdgPay. Réponds en français, avec précision et brièveté. Quand la question porte sur EdgPay, privilégie le fonctionnement réel de l application, sans inventer de règles métier. Quand la question est générale, réponds utilement mais sans sortir du cadre légal et sécurisé.'),
    ],

];
