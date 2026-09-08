<?php

namespace App\Domain\Metrics;

use App\Models\HypothesisMetricEvent;
use App\Models\ManagerAction;
use App\Models\Message;
use App\Models\Project;

class HypothesisMetricsRecorder
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(
        string $name,
        array $payload = [],
        ?Message $message = null,
        ?Project $project = null,
        ?ManagerAction $managerAction = null,
        ?int $projectId = null,
        ?int $managerActionId = null,
    ): void {
        HypothesisMetricEvent::query()->create([
            'name' => $name,
            'message_id' => $message?->id,
            'project_id' => $project?->id ?? $projectId ?? $message?->project_id,
            'manager_action_id' => $managerAction?->id ?? $managerActionId,
            'payload' => $payload === [] ? null : $payload,
        ]);
    }
}
