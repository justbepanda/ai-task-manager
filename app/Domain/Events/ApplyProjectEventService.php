<?php

namespace App\Domain\Events;

use App\Domain\ManagerActions\SupersedeStaleConversationManagerActions;
use App\Enums\EventType;
use App\Enums\ManagerActionStatus;
use App\Enums\ManagerActionType;
use App\Enums\MessageClassification;
use App\Enums\MessageProcessingStatus;
use App\Enums\ProjectStatus;
use App\Models\Event;
use App\Models\ManagerAction;
use App\Models\Message;
use App\Models\ProcessingLog;
use App\Models\Project;
use App\Models\ProjectStateLog;
use Illuminate\Support\Facades\DB;

class ApplyProjectEventService
{
    public function __construct(
        private readonly SupersedeStaleConversationManagerActions $supersedeStaleActions,
    ) {}

    /**
     * Apply a domain event to project state and optionally create a ManagerAction.
     * AI never mutates Project directly — only this service does.
     *
     * @return array{
     *     applied: bool,
     *     skipped: bool,
     *     noop: bool,
     *     manager_action_id: int|null,
     *     reason: string|null
     * }
     */
    public function handle(Event $event): array
    {
        return DB::transaction(function () use ($event): array {
            $event = Event::query()->lockForUpdate()->with(['message', 'project'])->findOrFail($event->id);

            if ($event->applied_at !== null) {
                return [
                    'applied' => false,
                    'skipped' => false,
                    'noop' => true,
                    'manager_action_id' => null,
                    'reason' => 'already_applied',
                ];
            }

            $message = $event->message;
            $projectId = $event->project_id ?? $message?->project_id;
            $confidence = $this->resolveConfidence($event, $message);
            $payload = is_array($event->payload) ? $event->payload : [];

            if ($projectId === null) {
                $managerAction = $this->createManagerActionIfNeeded($event, $message, $payload, null);
                $this->markNeedsManual($message);
                $this->logProcessing($message, 'event_apply_skipped', 'skipped', [
                    'event_id' => $event->id,
                    'reason' => 'no_project_id',
                    'manager_action_id' => $managerAction?->id,
                ]);

                return [
                    'applied' => false,
                    'skipped' => true,
                    'noop' => false,
                    'manager_action_id' => $managerAction?->id,
                    'reason' => 'no_project_id',
                ];
            }

            if ($this->canAutoApply($confidence)) {
                $project = Project::query()->lockForUpdate()->findOrFail($projectId);
                $this->applyToProject($project, $event, $message);
                $event->applied_at = now();
                $event->project_id = $projectId;
                $event->save();

                if ($message !== null) {
                    $message->processing_status = MessageProcessingStatus::Applied;
                    if ($message->project_id === null) {
                        $message->project_id = $projectId;
                    }
                    $message->save();
                }

                $managerAction = $this->createManagerActionIfNeeded($event, $message, $payload, $projectId);
                $this->logProcessing($message, 'event_applied', 'ok', [
                    'event_id' => $event->id,
                    'project_id' => $projectId,
                    'confidence' => $confidence,
                    'manager_action_id' => $managerAction?->id,
                ]);

                return [
                    'applied' => true,
                    'skipped' => false,
                    'noop' => false,
                    'manager_action_id' => $managerAction?->id,
                    'reason' => null,
                ];
            }

            $this->markNeedsManual($message);
            $managerAction = $this->createManagerActionIfNeeded($event, $message, $payload, $projectId);
            $this->logProcessing($message, 'event_apply_skipped', 'skipped', [
                'event_id' => $event->id,
                'project_id' => $projectId,
                'confidence' => $confidence,
                'auto_apply_threshold' => (float) config('ai.confidence.auto_apply', 0.85),
                'manual_review_threshold' => (float) config('ai.confidence.manual_review', 0.55),
                'reason' => 'confidence_below_auto_apply',
                'manager_action_id' => $managerAction?->id,
            ]);

            return [
                'applied' => false,
                'skipped' => true,
                'noop' => false,
                'manager_action_id' => $managerAction?->id,
                'reason' => 'confidence_below_auto_apply',
            ];
        });
    }

    private function resolveConfidence(Event $event, ?Message $message): float
    {
        if ($event->confidence !== null) {
            return (float) $event->confidence;
        }

        if ($message?->ai_confidence !== null) {
            return (float) $message->ai_confidence;
        }

        return 0.0;
    }

    private function canAutoApply(float $confidence): bool
    {
        return $confidence >= (float) config('ai.confidence.auto_apply', 0.85);
    }

    private function applyToProject(Project $project, Event $event, ?Message $message): void
    {
        $old = [
            'last_event_type' => $project->last_event_type,
            'last_event_at' => $project->last_event_at?->toIso8601String(),
            'last_summary' => $project->last_summary,
            'status' => $project->status->value,
        ];

        $project->last_event_type = $event->type->value;
        $project->last_event_at = now();
        $project->last_summary = $event->summary;

        if ($this->shouldActivatePausedProject($event->type, $project->status)) {
            $project->status = ProjectStatus::Active;
        }

        $project->save();

        ProjectStateLog::query()->create([
            'project_id' => $project->id,
            'event_id' => $event->id,
            'message_id' => $message?->id ?? $event->message_id,
            'change_type' => 'event_applied',
            'field' => null,
            'old_value' => null,
            'new_value' => null,
            'summary' => [
                'event_type' => $event->type->value,
                'old' => $old,
                'new' => [
                    'last_event_type' => $project->last_event_type,
                    'last_event_at' => $project->last_event_at?->toIso8601String(),
                    'last_summary' => $project->last_summary,
                    'status' => $project->status->value,
                ],
            ],
        ]);
    }

    private function shouldActivatePausedProject(EventType $type, ProjectStatus $status): bool
    {
        if ($status !== ProjectStatus::Paused) {
            return false;
        }

        return in_array($type, [
            EventType::ClientQuestion,
            EventType::ClientRequest,
            EventType::ClientReportedBug,
        ], true);
    }

    /**
     * Create pending ManagerAction when requires_manager or suggested_action needs a human.
     * Idempotent for the same event_id (reuses existing pending row).
     *
     * @param  array<string, mixed>  $payload
     */
    private function createManagerActionIfNeeded(
        Event $event,
        ?Message $message,
        array $payload,
        ?int $projectId,
    ): ?ManagerAction {
        $existing = ManagerAction::query()
            ->where('event_id', $event->id)
            ->where('status', ManagerActionStatus::Pending)
            ->first();

        if ($existing !== null) {
            $this->supersedeStaleActions->afterCreated($existing);

            return $existing->fresh() ?? $existing;
        }

        $requiresManager = (bool) ($message?->requires_manager ?? $payload['requires_manager'] ?? false);
        $suggested = $this->resolveSuggestedAction($payload);

        // Ignored mail (global blacklist) must not create manager work items.
        if ($message?->classification === MessageClassification::Ignored) {
            return null;
        }

        $needsHuman = $requiresManager
            || in_array($suggested, [
                ManagerActionType::Reply,
                ManagerActionType::Review,
                ManagerActionType::FollowUp,
            ], true);

        if (! $needsHuman) {
            return null;
        }

        $actionType = $suggested;
        if ($actionType === ManagerActionType::NoAction) {
            $actionType = ManagerActionType::Review;
        }

        $draft = $payload['draft_reply'] ?? null;
        if (! is_string($draft) || trim($draft) === '') {
            $draft = null;
        }

        $reason = $payload['reason'] ?? null;
        if (! is_string($reason) || trim($reason) === '') {
            $reason = $event->summary;
        }

        $action = ManagerAction::query()->create([
            'project_id' => $projectId ?? $event->project_id ?? $message?->project_id,
            'event_id' => $event->id,
            'message_id' => $message?->id ?? $event->message_id,
            'action_type' => $actionType,
            'status' => ManagerActionStatus::Pending,
            'reason' => $reason,
            'draft_reply' => $draft,
        ]);

        $this->supersedeStaleActions->afterCreated($action);

        return $action->fresh() ?? $action;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveSuggestedAction(array $payload): ManagerActionType
    {
        $raw = $payload['suggested_action'] ?? null;

        if (is_string($raw)) {
            return ManagerActionType::tryFrom($raw) ?? ManagerActionType::NoAction;
        }

        return ManagerActionType::NoAction;
    }

    private function markNeedsManual(?Message $message): void
    {
        if ($message === null) {
            return;
        }

        if (in_array($message->processing_status, [
            MessageProcessingStatus::Applied,
            MessageProcessingStatus::NeedsManual,
        ], true)) {
            return;
        }

        $message->processing_status = MessageProcessingStatus::NeedsManual;
        $message->save();
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function logProcessing(?Message $message, string $step, string $status, array $details): void
    {
        if ($message === null) {
            return;
        }

        ProcessingLog::query()->create([
            'message_id' => $message->id,
            'step' => $step,
            'status' => $status,
            'details' => $details,
        ]);
    }
}
