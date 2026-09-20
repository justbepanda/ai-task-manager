<?php

declare(strict_types=1);

namespace App\Domain\Settings;

use App\Models\AppSetting;

class AppSettingService
{
    /**
     * @param  array{
     *     auto_create_projects?: bool,
     *     auto_create_confidence_threshold?: float|int|string,
     *     auto_assign_projects_llm?: bool,
     *     auto_assign_llm_confidence_threshold?: float|int|string
     * }  $data
     */
    public function save(array $data): AppSetting
    {
        $settings = AppSetting::current();

        if (array_key_exists('auto_create_projects', $data)) {
            $settings->auto_create_projects = (bool) $data['auto_create_projects'];
        }

        if (array_key_exists('auto_create_confidence_threshold', $data)) {
            $threshold = (float) $data['auto_create_confidence_threshold'];
            $settings->auto_create_confidence_threshold = max(0.0, min(1.0, $threshold));
        }

        if (array_key_exists('auto_assign_projects_llm', $data)) {
            $settings->auto_assign_projects_llm = (bool) $data['auto_assign_projects_llm'];
        }

        if (array_key_exists('auto_assign_llm_confidence_threshold', $data)) {
            $threshold = (float) $data['auto_assign_llm_confidence_threshold'];
            $settings->auto_assign_llm_confidence_threshold = max(0.0, min(1.0, $threshold));
        }

        $settings->save();

        return $settings->fresh();
    }
}
