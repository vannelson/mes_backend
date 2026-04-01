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
    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL'),
    ],
    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL'),
    ],
    'puter' => [
        'key' => env('PUTER_API_KEY'),
        'endpoint' => env('PUTER_API_ENDPOINT'),
    ],
    'firebase' => [
        'database_url' => env('FIREBASE_DATABASE_URL'),
        'service_account_path' => env('FIREBASE_SERVICE_ACCOUNT_PATH'),
    ],
    'mailtrap' => [
        'api_token' => env('MAILTRAP_API_TOKEN'),
        'endpoint' => env('MAILTRAP_ENDPOINT', 'https://send.api.mailtrap.io/api/send'),
        'from_address' => env('MAIL_FROM_ADDRESS', env('MAIL_FROM')),
        'from_name' => env('MAIL_FROM_NAME', env('APP_NAME', 'MES')),
        'category' => env('MAILTRAP_CATEGORY', 'MES Automation'),
        'timeout' => env('MAILTRAP_TIMEOUT', 10),
    ],
    'operation_triggers' => [
        'execute_key' => env('OPERATION_TRIGGERS_EXECUTE_KEY'),
        'api_base_url' => env('OPERATION_TRIGGERS_API_BASE_URL'),
    ],
    'libretranslate' => [
        'endpoint' => env('LIBRETRANSLATE_ENDPOINT', 'http://127.0.0.1:5000/translate'),
        'api_key' => env('LIBRETRANSLATE_API_KEY'),
    ],

];
