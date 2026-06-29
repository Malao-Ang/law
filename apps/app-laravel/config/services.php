<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'ocr' => [
        'base_url' => env('OCR_SERVICE_BASE_URL', 'http://ocr-service:8010'),
        'shared_storage_root' => env('OCR_SHARED_STORAGE_ROOT', '/data/poc'),
        'internal_callback_url' => env('INTERNAL_CALLBACK_URL', 'http://laravel-app:8000/api/internal/pipeline-callback'),
        'enable_ai_correction' => filter_var(env('AI_CORRECTION_ENABLED', true), FILTER_VALIDATE_BOOL),
        'normalize_autocorrect_min_confidence' => env('OCR_NORMALIZE_AUTOCORRECT_MIN_CONFIDENCE', 1.0),
    ],

];
