<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\AppSetting;
use Illuminate\Database\Seeder;

class AppSettingSeeder extends Seeder
{
    public function run(): void
    {
        $existing = AppSetting::query()->find(1);
        if ($existing !== null) {
            return;
        }

        AppSetting::query()->create([
            'id' => 1,
            'auto_create_projects' => true,
            'auto_create_confidence_threshold' => 0.95,
            'auto_assign_projects_llm' => true,
            'auto_assign_llm_confidence_threshold' => 0.90,
        ]);
    }
}
