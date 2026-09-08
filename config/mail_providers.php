<?php

return [
    'default' => env('MAIL_PROVIDER', 'yandex'),

    'sync_interval_minutes' => (int) env('MAIL_SYNC_INTERVAL_MINUTES', 5),

    'yandex' => [
        'host' => env('YANDEX_IMAP_HOST', 'imap.yandex.ru'),
        'port' => (int) env('YANDEX_IMAP_PORT', 993),
        'encryption' => env('YANDEX_IMAP_ENCRYPTION', 'ssl'),
        'username' => env('YANDEX_IMAP_USERNAME'),
        'password' => env('YANDEX_IMAP_PASSWORD'),
        'folder' => env('YANDEX_IMAP_FOLDER', 'INBOX'),
        // First-run policy: never import the whole historic mailbox.
        'first_sync_limit' => (int) env('YANDEX_IMAP_FIRST_SYNC_LIMIT', 100),
    ],
];
