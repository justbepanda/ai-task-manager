<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Orchid\Screen\AsSource;

#[Fillable(['message_id', 'step', 'status', 'details'])]
class ProcessingLog extends Model
{
    use AsSource;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ProcessingLog $log): void {
            $log->created_at ??= now();
        });
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
