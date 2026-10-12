<?php

declare(strict_types=1);

namespace App\Domain\Projects;

use App\Domain\Metrics\HypothesisMetricsRecorder;
use App\Domain\ManagerActions\SupersedeStaleConversationManagerActions;
use App\Enums\ManagerActionStatus;
use App\Enums\ManagerActionType;
use App\Models\ManagerAction;
use App\Models\Message;
use App\Models\ProcessingLog;

/**
 * Subject status "(Обратная связь)" → requires manager action.
 */
class ApplyRedmineFeedbackRequiresManager
{
    public function __construct(
        private readonly RedmineMailPeopleExtractor $peopleExtractor,
        private readonly HypothesisMetricsRecorder $metrics,
        private readonly SupersedeStaleConversationManagerActions $supersedeStaleActions,
    ) {}

    public function handle(Message $message): Message
    {
        $subject = (string) ($message->subject ?? '');
        if (! $this->peopleExtractor->subjectRequiresFeedback($subject)) {
            return $message;
        }

        if ($message->requires_manager !== true) {
            $message->requires_manager = true;
        }

        $metadata = is_array($message->metadata) ? $message->metadata : [];
        $metadata['redmine_feedback'] = true;
        $message->metadata = $metadata;
        $message->save();

        ProcessingLog::query()->create([
            'message_id' => $message->id,
            'step' => 'redmine_feedback_requires_manager',
            'status' => 'ok',
            'details' => [
                'subject' => mb_substr($subject, 0, 200),
                'project_id' => $message->project_id,
            ],
        ]);

        $this->metrics->record(
            'event_requires_manager',
            ['reason' => 'redmine_feedback_status'],
            $message,
            projectId: $message->project_id,
        );

        $this->ensureManagerAction($message);

        return $message->fresh() ?? $message;
    }

    private function ensureManagerAction(Message $message): void
    {
        // Event already created by Hermes analyze → ApplyEventJob creates ManagerAction.
        if ($message->events()->exists()) {
            return;
        }

        $exists = ManagerAction::query()
            ->where('message_id', $message->id)
            ->where('status', ManagerActionStatus::Pending)
            ->exists();

        if ($exists) {
            return;
        }

        $action = ManagerAction::query()->create([
            'project_id' => $message->project_id,
            'event_id' => null,
            'message_id' => $message->id,
            'action_type' => ManagerActionType::Review,
            'status' => ManagerActionStatus::Pending,
            'reason' => 'Redmine: статус «Обратная связь» — требуется действие менеджера',
            'draft_reply' => null,
        ]);

        $this->supersedeStaleActions->afterCreated($action);
    }
}
