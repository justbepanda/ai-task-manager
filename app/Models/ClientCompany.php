<?php

namespace App\Models;

use Database\Factories\ClientCompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Orchid\Screen\AsSource;

#[Fillable([
    'name',
    'description',
    'notes',
])]
class ClientCompany extends Model
{
    /** @use HasFactory<ClientCompanyFactory> */
    use AsSource, HasFactory;

    public function contacts(): HasMany
    {
        return $this->hasMany(ClientContact::class);
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'client_company_project');
    }
}
