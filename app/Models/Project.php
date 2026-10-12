<?php

namespace App\Models;

use App\Enums\ProjectStatus;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Orchid\Screen\AsSource;

#[Fillable([
    'name',
    'description',
    'status',
    'manager_executor_id',
    'last_event_type',
    'last_event_at',
    'last_summary',
    'agent_state',
])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use AsSource, HasFactory;

    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
            'last_event_at' => 'datetime',
            'agent_state' => 'array',
        ];
    }

    public function managerExecutor(): BelongsTo
    {
        return $this->belongsTo(Executor::class, 'manager_executor_id');
    }

    public function instruction(): HasOne
    {
        return $this->hasOne(ProjectInstruction::class);
    }

    public function matchingRule(): HasOne
    {
        return $this->hasOne(ProjectMatchingRule::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(ProjectTask::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    public function managerActions(): HasMany
    {
        return $this->hasMany(ManagerAction::class);
    }

    public function stateLogs(): HasMany
    {
        return $this->hasMany(ProjectStateLog::class);
    }

    public function clientCompanies(): BelongsToMany
    {
        return $this->belongsToMany(ClientCompany::class, 'client_company_project');
    }

    public function clientContacts(): BelongsToMany
    {
        return $this->belongsToMany(ClientContact::class, 'client_contact_project');
    }

    public function executors(): BelongsToMany
    {
        return $this->belongsToMany(Executor::class, 'executor_project');
    }

    /**
     * Distinct managers assigned on project tasks (for read-only project UI).
     *
     * @return \Illuminate\Support\Collection<int, Executor>
     */
    public function taskManagers()
    {
        return Executor::query()
            ->whereIn('id', $this->tasks()->whereNotNull('manager_executor_id')->select('manager_executor_id'))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();
    }

    /**
     * Distinct executors assigned on project tasks (for read-only project UI).
     *
     * @return \Illuminate\Support\Collection<int, Executor>
     */
    public function taskExecutors()
    {
        return Executor::query()
            ->whereIn('id', function ($q): void {
                $q->select('executor_id')
                    ->from('executor_project_task')
                    ->whereIn('project_task_id', $this->tasks()->select('id'));
            })
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();
    }
}
