<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['project_id', 'body'])]
class ProjectInstruction extends Model
{
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
