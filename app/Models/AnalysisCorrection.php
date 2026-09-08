<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Orchid\Screen\AsSource;

#[Fillable(['message_id', 'field', 'ai_value', 'manager_value'])]
class AnalysisCorrection extends Model
{
    use AsSource;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (AnalysisCorrection $correction): void {
            $correction->created_at ??= now();
        });
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
