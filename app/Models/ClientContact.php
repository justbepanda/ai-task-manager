<?php

namespace App\Models;

use App\Models\Concerns\HasPersonName;
use Database\Factories\ClientContactFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Orchid\Screen\AsSource;

#[Fillable([
    'client_company_id',
    'last_name',
    'first_name',
    'middle_name',
    'position',
    'emails',
    'telegram',
    'phone',
    'notes',
])]
class ClientContact extends Model
{
    /** @use HasFactory<ClientContactFactory> */
    use AsSource, HasFactory, HasPersonName;

    protected function casts(): array
    {
        return [
            'emails' => 'array',
        ];
    }

    public function clientCompany(): BelongsTo
    {
        return $this->belongsTo(ClientCompany::class);
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'client_contact_project');
    }
}
