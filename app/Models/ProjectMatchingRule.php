<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'project_id',
    'client_emails',
    'staff_emails',
    'domains',
    'subject_patterns',
    'keywords',
    'technical_sources',
    'custom_rules',
])]
class ProjectMatchingRule extends Model
{
    protected function casts(): array
    {
        return [
            'client_emails' => 'array',
            'staff_emails' => 'array',
            'domains' => 'array',
            'subject_patterns' => 'array',
            'keywords' => 'array',
            'technical_sources' => 'array',
            'custom_rules' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
