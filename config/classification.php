<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Technical senders (email or domain)
    |--------------------------------------------------------------------------
    |
    | Matched against From address (exact email) or sender domain.
    |
    */
    'technical_senders' => [
        'noreply@github.com',
        'notifications@github.com',
        'noreply@gitlab.com',
        'gitlab@gitlab.com',
        'redmine',
        'jira',
        'aspro',
        'noreply@redmine',
        'mailer-daemon',
        'postmaster',
        'github.com',
        'gitlab.com',
        'atlassian.net',
        'aspro.cloud',
        'aspro.ru',
    ],

    'technical_subject_patterns' => [
        '[Redmine]',
        '[Jira]',
        'Jira',
        'Aspro',
        'GitLab',
        'GitHub',
        'Automated message',
        'Автоматическое уведомление',
        'Do not reply',
        'Please do not reply',
        'noreply',
    ],

    'technical_body_patterns' => [
        'This is an automated message',
        'You are receiving this email because',
        'не отвечайте на это письмо',
    ],

    /*
    |--------------------------------------------------------------------------
    | Newsletter heuristics
    |--------------------------------------------------------------------------
    */
    'newsletter_header_hints' => true,

    'newsletter_senders' => [
        'newsletter',
        'noreply@mailchimp.com',
        'bounce',
    ],

    'newsletter_body_patterns' => [
        'unsubscribe',
        'отписаться',
        'list-unsubscribe',
    ],

    /*
    |--------------------------------------------------------------------------
    | Personal allowlist (optional; empty = skip personal rule)
    |--------------------------------------------------------------------------
    */
    'personal_emails' => [],

    'personal_domains' => [],

    /*
    |--------------------------------------------------------------------------
    | Project matching thresholds
    |--------------------------------------------------------------------------
    */
    'project_match_auto_threshold' => (float) env('PROJECT_MATCH_AUTO_THRESHOLD', 0.8),

    /** Lower threshold for UI bulk-assignment suggestions (not auto-apply). */
    'project_match_suggest_threshold' => (float) env('PROJECT_MATCH_SUGGEST_THRESHOLD', 0.55),

    'project_match_ambiguous_margin' => (float) env('PROJECT_MATCH_AMBIGUOUS_MARGIN', 0.1),

    /*
    |--------------------------------------------------------------------------
    | Redmine subject → project (deterministic + AI hint)
    |--------------------------------------------------------------------------
    |
    | For these senders, project name is taken primarily from the [brackets]
    | in the subject (before " - Tracker #id"), not from From alone.
    |
    */
    'redmine_subject_project' => [
        'senders' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('REDMINE_SUBJECT_PROJECT_SENDERS', 'redmine@kelnik.pro')),
        ))),
        'match_score' => (float) env('REDMINE_SUBJECT_PROJECT_MATCH_SCORE', 0.97),
    ],

    /*
    |--------------------------------------------------------------------------
    | Classification confidence scores (rule-based, not probabilities)
    |--------------------------------------------------------------------------
    */
    'confidence' => [
        'technical_sender' => 0.95,
        'technical_project_source' => 0.90,
        'technical_pattern' => 0.85,
        'newsletter_header' => 0.95,
        'newsletter_pattern' => 0.80,
        'personal' => 0.85,
        'client_email' => 0.92,
        'client_domain' => 0.85,
        'client_subject' => 0.78,
        'client_keyword' => 0.70,
        'ignored' => 0.99,
        'unknown' => 0.20,
    ],

];
