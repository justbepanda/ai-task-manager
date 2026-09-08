<?php

namespace App\Models;

use App\Enums\EventType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Orchid\Screen\AsSource;

#[Fillable([
    'message_id',
    'project_id',
    'type',
    'summary',
    'confidence',
    'payload',
    'applied_at',
])]
class Event extends Model
{
    use AsSource;
    protected function casts(): array
    {
        return [
            'type' => EventType::class,
            'confidence' => 'decimal:4',
            'payload' => 'array',
            'applied_at' => 'datetime',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function managerActions(): HasMany
    {
        return $this->hasMany(ManagerAction::class);
    }
}
