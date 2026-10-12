<?php

namespace App\Domain\Messages;

use App\Domain\Conversations\PropagateProjectToConversationMessages;
use App\Domain\Metrics\HypothesisMetricsRecorder;
use App\Enums\MessageClassification;
use App\Enums\MessageProcessingStatus;
use App\Models\AnalysisCorrection;
use App\Models\Message;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MessageManualOverrideService
{
    public function __construct(
        private readonly HypothesisMetricsRecorder $metrics,
        private readonly PropagateProjectToConversationMessages $propagateProject,
    ) {}

    /**
     * Apply manager overrides for classification and/or project.
     * Writes analysis_corrections when values change from previous AI/system values.
     *
     * @param  array{
     *     classification?: MessageClassification|string|null,
     *     project_id?: int|null,
     * }  $overrides
     */
    public function apply(Message $message, array $overrides): Message
    {
        $propagatedProjectId = null;

        $result = DB::transaction(function () use ($message, $overrides, &$propagatedProjectId): Message {
            $changedFields = [];

            if (array_key_exists('classification', $overrides)) {
                if ($this->overrideClassification($message, $overrides['classification'])) {
                    $changedFields[] = 'classification';
                }
            }

            if (array_key_exists('project_id', $overrides)) {
                if ($this->overrideProject($message, $overrides['project_id'])) {
                    $changedFields[] = 'project_id';
                    if ($message->project_id !== null) {
                        $propagatedProjectId = (int) $message->project_id;
                    }
                }
            }

            $message->save();

            if ($changedFields !== []) {
                $this->metrics->record('analysis_corrected', [
                    'fields' => $changedFields,
                ], $message);
            }

            return $message->fresh(['project', 'events', 'conversation']);
        });

        if ($propagatedProjectId !== null && $result !== null) {
            $this->propagateProject->handle($result, $propagatedProjectId);

            return $result->fresh(['project', 'events', 'conversation']) ?? $result;
        }

        return $result;
    }

    /**
     * Whether Gemini analysis should run after a manual override.
     * Same rules as post-classification pipeline: client/unknown with a project,
     * and not already analyzed.
     */
    public function shouldQueueAnalyze(Message $message): bool
    {
        if (in_array($message->processing_status, [
            MessageProcessingStatus::Analyzed,
            MessageProcessingStatus::Applied,
        ], true)) {
            return false;
        }

        if ($message->project_id === null) {
            return false;
        }

        return match ($message->classification) {
            MessageClassification::Client,
            MessageClassification::SiteLead,
            MessageClassification::Unknown => true,
            default => false,
        };
    }

    private function overrideClassification(Message $message, MessageClassification|string|null $value): bool
    {
        $new = $value === null || $value === ''
            ? null
            : ($value instanceof MessageClassification
                ? $value
                : MessageClassification::from((string) $value));

        $old = $message->classification;

        if ($old?->value === $new?->value) {
            return false;
        }

        AnalysisCorrection::query()->create([
            'message_id' => $message->id,
            'field' => 'classification',
            'ai_value' => $old?->value,
            'manager_value' => $new?->value,
        ]);

        $message->classification = $new;

        if ($message->processing_status === MessageProcessingStatus::NeedsManual) {
            $message->processing_status = MessageProcessingStatus::Classified;
        }

        return true;
    }

    private function overrideProject(Message $message, int|string|null $projectId): bool
    {
        $newId = $projectId === null || $projectId === '' ? null : (int) $projectId;

        if ($newId !== null && ! Project::query()->whereKey($newId)->exists()) {
            throw ValidationException::withMessages([
                'project_id' => 'Проект не найден.',
            ]);
        }

        $oldId = $message->project_id;

        if ($oldId === $newId) {
            return false;
        }

        AnalysisCorrection::query()->create([
            'message_id' => $message->id,
            'field' => 'project_id',
            'ai_value' => $oldId !== null ? (string) $oldId : null,
            'manager_value' => $newId !== null ? (string) $newId : null,
        ]);

        $message->project_id = $newId;

        if ($message->processing_status === MessageProcessingStatus::NeedsManual && $newId !== null) {
            $message->processing_status = MessageProcessingStatus::Classified;
        }

        return true;
    }
}
