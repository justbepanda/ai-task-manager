<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Orchid\Screen\AsSource;

#[Fillable([
    'auto_create_projects',
    'auto_create_confidence_threshold',
    'auto_assign_projects_llm',
    'auto_assign_llm_confidence_threshold',
])]
class AppSetting extends Model
{
    use AsSource;

    protected function casts(): array
    {
        return [
            'auto_create_projects' => 'boolean',
            'auto_create_confidence_threshold' => 'float',
            'auto_assign_projects_llm' => 'boolean',
            'auto_assign_llm_confidence_threshold' => 'float',
        ];
    }

    public static function current(): self
    {
        return static::query()->firstOrCreate(
            ['id' => 1],
            [
                'auto_create_projects' => true,
                'auto_create_confidence_threshold' => 0.95,
                'auto_assign_projects_llm' => true,
                'auto_assign_llm_confidence_threshold' => 0.90,
            ],
        );
    }
}
