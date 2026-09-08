<?php

namespace App\Models;

use App\Models\Concerns\HasPersonName;
use Database\Factories\ExecutorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Orchid\Screen\AsSource;

#[Fillable([
    'last_name',
    'first_name',
    'middle_name',
    'position',
    'emails',
    'telegram',
    'phone',
    'notes',
    'is_active',
])]
class Executor extends Model
{
    /** @use HasFactory<ExecutorFactory> */
    use AsSource, HasFactory, HasPersonName;

    protected function casts(): array
    {
        return [
            'emails' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'executor_project');
    }
}
