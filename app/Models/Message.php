<?php

namespace App\Models;

use App\Enums\MailSource;
use App\Enums\MessageClassification;
use App\Enums\MessageProcessingStatus;
use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Orchid\Screen\AsSource;

#[Fillable([
    'source',
    'external_id',
    'conversation_id',
    'project_id',
    'user_id',
    'sender',
    'sender_role',
    'recipients',
    'cc',
    'subject',
    'body',
    'received_at',
    'headers',
    'metadata',
    'classification',
    'processing_status',
    'ai_summary',
    'ai_confidence',
    'requires_manager',
    'processing_error',
])]
class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use AsSource, HasFactory;

    protected function casts(): array
    {
        return [
            'source' => MailSource::class,
            'recipients' => 'array',
            'cc' => 'array',
            'headers' => 'array',
            'metadata' => 'array',
            'classification' => MessageClassification::class,
            'processing_status' => MessageProcessingStatus::class,
            'received_at' => 'datetime',
            'ai_confidence' => 'decimal:4',
            'requires_manager' => 'boolean',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function raw(): HasOne
    {
        return $this->hasOne(MessageRaw::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    public function managerActions(): HasMany
    {
        return $this->hasMany(ManagerAction::class);
    }

    public function processingLogs(): HasMany
    {
        return $this->hasMany(ProcessingLog::class);
    }

    public function analysisCorrections(): HasMany
    {
        return $this->hasMany(AnalysisCorrection::class);
    }
}
