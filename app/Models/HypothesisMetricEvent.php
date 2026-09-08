<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'name',
    'message_id',
    'project_id',
    'manager_action_id',
    'payload',
])]
class HypothesisMetricEvent extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (HypothesisMetricEvent $event): void {
            $event->created_at ??= now();
        });
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function managerAction(): BelongsTo
    {
        return $this->belongsTo(ManagerAction::class);
    }
}
