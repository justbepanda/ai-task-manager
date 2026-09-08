<?php

return [
    'provider' => env('AI_PROVIDER', 'gemini'),

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-3.6-flash'),
        'timeout' => (int) env('GEMINI_TIMEOUT', 30),
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
    ],

    'confidence' => [
        'auto_apply' => (float) env('AI_CONFIDENCE_AUTO_APPLY', 0.85),
        'manual_review' => (float) env('AI_CONFIDENCE_MANUAL_REVIEW', 0.55),
    ],

    'context' => [
        'conversation_messages_limit' => (int) env('AI_CONTEXT_CONVERSATION_LIMIT', 10),
        'recent_events_limit' => (int) env('AI_CONTEXT_EVENTS_LIMIT', 10),
    ],
];
