<?php

namespace App\Models;

use App\Models\Concerns\HasPersonName;
use Database\Factories\ExecutorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    public function projectTasks(): BelongsToMany
    {
        return $this->belongsToMany(ProjectTask::class, 'executor_project_task')->withTimestamps();
    }

    public function managedProjectTasks(): HasMany
    {
        return $this->hasMany(ProjectTask::class, 'manager_executor_id');
    }
}
