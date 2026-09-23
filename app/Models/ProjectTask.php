<?php

namespace App\Models;

use App\Enums\ProjectTaskSource;
use App\Enums\ProjectTaskStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Orchid\Screen\AsSource;

#[Fillable([
    'project_id',
    'source',
    'external_key',
    'title',
    'status',
    'last_message_at',
])]
class ProjectTask extends Model
{
    use AsSource;

    protected function casts(): array
    {
        return [
            'source' => ProjectTaskSource::class,
            'status' => ProjectTaskStatus::class,
            'last_message_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function displayLabel(): string
    {
        $prefix = match ($this->source) {
            ProjectTaskSource::Redmine => '#'.$this->external_key,
            ProjectTaskSource::Jira => $this->external_key,
            ProjectTaskSource::EmailThread => 'Диалог',
        };

        $title = trim((string) ($this->title ?? ''));
        if ($title === '' || $title === $prefix) {
            return $prefix;
        }

        return $prefix.' — '.$title;
    }
}
