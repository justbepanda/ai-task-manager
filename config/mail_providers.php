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
        // Used when YANDEX_IMAP_SYNC_ALL_FOLDERS=false, and as the folder opened to list mailboxes.
        'folder' => env('YANDEX_IMAP_FOLDER', 'INBOX'),
        // Sync every IMAP folder (user labels), not only INBOX.
        'sync_all_folders' => filter_var(env('YANDEX_IMAP_SYNC_ALL_FOLDERS', true), FILTER_VALIDATE_BOOL),
        // Substring match (case-insensitive) against folder name / UTF7-decoded name.
        'folder_exclude' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env(
                'YANDEX_IMAP_FOLDER_EXCLUDE',
                'Spam,Junk,Drafts,Draft,Sent,Outbox,Спам,Черновик,Отправленн,Исходящ',
            )),
        ))),
        // First-run policy: never import the whole historic mailbox (per folder).
        'first_sync_limit' => (int) env('YANDEX_IMAP_FIRST_SYNC_LIMIT', 100),
    ],
];
