<?php

namespace App\Models;

use App\Enums\ManagerActionStatus;
use App\Enums\ManagerActionType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Orchid\Screen\AsSource;

#[Fillable([
    'project_id',
    'event_id',
    'message_id',
    'action_type',
    'status',
    'reason',
    'draft_reply',
    'final_reply',
    'manager_notes',
    'decided_at',
])]
class ManagerAction extends Model
{
    use AsSource;
    protected function casts(): array
    {
        return [
            'action_type' => ManagerActionType::class,
            'status' => ManagerActionStatus::class,
            'decided_at' => 'datetime',
        ];
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
