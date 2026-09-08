<?php

namespace App\Models;

use App\Enums\ProjectStatus;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Orchid\Screen\AsSource;

#[Fillable([
    'name',
    'description',
    'status',
    'last_event_type',
    'last_event_at',
    'last_summary',
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
        ];
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
}
