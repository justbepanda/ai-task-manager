<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'project_id',
    'event_id',
    'message_id',
    'change_type',
    'field',
    'old_value',
    'new_value',
    'summary',
])]
class ProjectStateLog extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ProjectStateLog $log): void {
            $log->created_at ??= now();
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
