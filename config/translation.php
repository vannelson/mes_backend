<?php

return [
    'source_locale' => env('TRANSLATION_SOURCE_LOCALE', env('APP_LOCALE', 'en')),
    'driver' => env('TRANSLATION_DRIVER', 'openai'),
    'cache_prefix' => env('TRANSLATION_CACHE_PREFIX', 'mes_translation:'),
    'cache_ttl_minutes' => (int) env('TRANSLATION_CACHE_TTL_MINUTES', 43200),
    'chunk_size' => (int) env('TRANSLATION_CHUNK_SIZE', 40),
    'supported_locales' => [
        'en' => [
            'html_lang' => 'en',
            'accept_language' => 'en-SG,en;q=0.9',
            'provider_locale' => 'English',
        ],
        'zh-Hans' => [
            'html_lang' => 'zh-CN',
            'accept_language' => 'zh-CN,zh-SG,zh;q=0.9,en;q=0.6',
            'provider_locale' => 'Simplified Chinese',
        ],
        'ms' => [
            'html_lang' => 'ms',
            'accept_language' => 'ms-SG,ms-MY,ms;q=0.9,en;q=0.6',
            'provider_locale' => 'Malay',
        ],
        'ta' => [
            'html_lang' => 'ta',
            'accept_language' => 'ta-SG,ta;q=0.9,en;q=0.6',
            'provider_locale' => 'Tamil',
        ],
    ],
    'aliases' => [
        'en-us' => 'en',
        'en-sg' => 'en',
        'zh' => 'zh-Hans',
        'zh-cn' => 'zh-Hans',
        'zh-sg' => 'zh-Hans',
        'zh-hans' => 'zh-Hans',
        'ms-my' => 'ms',
        'ms-sg' => 'ms',
        'ta-in' => 'ta',
        'ta-sg' => 'ta',
    ],
    'openai' => [
        'model' => env('TRANSLATION_OPENAI_MODEL', env('OPENAI_MODEL', 'gpt-4o-mini')),
        'endpoint' => env('TRANSLATION_OPENAI_ENDPOINT', 'https://api.openai.com/v1/chat/completions'),
        'timeout' => (int) env('TRANSLATION_OPENAI_TIMEOUT', 45),
    ],
    'libretranslate' => [
        'endpoint' => env('LIBRETRANSLATE_ENDPOINT', 'http://127.0.0.1:5000/translate'),
        'api_key' => env('LIBRETRANSLATE_API_KEY'),
        'timeout' => (int) env('LIBRETRANSLATE_TIMEOUT', 20),
    ],
];
