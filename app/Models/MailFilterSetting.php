<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Orchid\Screen\AsSource;

#[Fillable([
    'blocked_emails',
    'blocked_domains',
    'subject_stop_words',
    'body_stop_words',
])]
class MailFilterSetting extends Model
{
    use AsSource;

    protected function casts(): array
    {
        return [
            'blocked_emails' => 'array',
            'blocked_domains' => 'array',
            'subject_stop_words' => 'array',
            'body_stop_words' => 'array',
        ];
    }

    public static function current(): self
    {
        return static::query()->firstOrCreate(
            ['id' => 1],
            [
                'blocked_emails' => [],
                'blocked_domains' => [],
                'subject_stop_words' => [],
                'body_stop_words' => [],
            ],
        );
    }
}
