<?php

return [
    'provider' => env('AI_PROVIDER', 'gemini'),

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-3.6-flash'),
        // Comma-separated fallbacks when primary returns 429/5xx after retries.
        'fallback_models' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('GEMINI_FALLBACK_MODELS', 'gemini-3.5-flash,gemini-3.5-flash-lite')),
        ))),
        'timeout' => (int) env('GEMINI_TIMEOUT', 60),
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        // Gemini 3.x thinks by default; low is enough for structured JSON assign/analyze.
        'thinking_level' => env('GEMINI_THINKING_LEVEL', 'low'),
        'retry' => [
            'max_attempts' => (int) env('GEMINI_RETRY_ATTEMPTS', 3),
            'base_delay_ms' => (int) env('GEMINI_RETRY_BASE_DELAY_MS', 800),
        ],
    ],

    'confidence' => [
        'auto_apply' => (float) env('AI_CONFIDENCE_AUTO_APPLY', 0.85),
        'manual_review' => (float) env('AI_CONFIDENCE_MANUAL_REVIEW', 0.55),
    ],

    'context' => [
        'conversation_messages_limit' => (int) env('AI_CONTEXT_CONVERSATION_LIMIT', 10),
        'recent_events_limit' => (int) env('AI_CONTEXT_EVENTS_LIMIT', 10),
        'corrections_limit' => (int) env('AI_CONTEXT_CORRECTIONS_LIMIT', 15),
        // Prefer latest reply over nested quotes for Hermes prompts.
        'latest_body_chars' => (int) env('AI_CONTEXT_LATEST_BODY_CHARS', 4000),
        'thread_excerpt_chars' => (int) env('AI_CONTEXT_THREAD_EXCERPT_CHARS', 800),
        'conversation_body_chars' => (int) env('AI_CONTEXT_CONVERSATION_BODY_CHARS', 600),
    ],

    'follow_up' => [
        'enabled' => (bool) env('AI_FOLLOW_UP_ENABLED', true),
        'silence_days' => (int) env('AI_FOLLOW_UP_SILENCE_DAYS', 3),
        'limit_per_run' => (int) env('AI_FOLLOW_UP_LIMIT', 20),
        // Cron hour (server time) for daily silence scan.
        'schedule_hour' => (int) env('AI_FOLLOW_UP_SCHEDULE_HOUR', 9),
    ],

    'assign_suggest' => [
        'limit' => (int) env('AI_ASSIGN_SUGGEST_LIMIT', 40),
        'body_chars' => (int) env('AI_ASSIGN_SUGGEST_BODY_CHARS', 400),
    ],

    'people_assign' => [
        'body_chars' => (int) env('AI_PEOPLE_ASSIGN_BODY_CHARS', 1200),
    ],
];
