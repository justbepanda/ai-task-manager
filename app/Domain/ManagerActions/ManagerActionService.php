<?php

namespace App\Domain\ManagerActions;

use App\Domain\Metrics\HypothesisMetricsRecorder;
use App\Enums\ManagerActionStatus;
use App\Models\AnalysisCorrection;
use App\Models\ManagerAction;
use App\Models\Message;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManagerActionService
{
    public function __construct(
        private readonly HypothesisMetricsRecorder $metrics,
    ) {}

    /**
     * Accept the draft as-is (or with an optional final reply). MVP does not send email.
     */
    public function approve(ManagerAction $action, ?string $finalReply = null): void
    {
        $this->assertTransitionable(
            $action,
            [ManagerActionStatus::Pending, ManagerActionStatus::Edited],
            'approve',
        );

        $previousStatus = $action->status;

        $resolvedFinal = $finalReply !== null && trim($finalReply) !== ''
            ? $finalReply
            : $action->draft_reply;

        $action->fill([
            'status' => ManagerActionStatus::Approved,
            'final_reply' => $resolvedFinal,
            'decided_at' => now(),
        ]);
        $action->save();

        // draft_edited is recorded on edit; approve-from-pending = unchanged draft.
        if ($previousStatus !== ManagerActionStatus::Edited) {
            $this->metrics->record(
                'draft_approved_unchanged',
                ['action_type' => $action->action_type->value],
                $action->message_id !== null ? Message::query()->find($action->message_id) : null,
                projectId: $action->project_id,
                managerActionId: $action->id,
            );
        }
    }

    /**
     * Update the draft reply. Records analysis_correction when the draft changes.
     */
    public function edit(ManagerAction $action, string $draftReply, ?string $notes = null): void
    {
        $this->assertTransitionable(
            $action,
            [ManagerActionStatus::Pending, ManagerActionStatus::Edited],
            'edit',
        );

        $oldDraft = $action->draft_reply;

        DB::transaction(function () use ($action, $draftReply, $notes, $oldDraft): void {
            $action->fill([
                'draft_reply' => $draftReply,
                'status' => ManagerActionStatus::Edited,
                'manager_notes' => $notes ?? $action->manager_notes,
            ]);
            $action->save();

            if ($action->message_id !== null && (string) $oldDraft !== (string) $draftReply) {
                AnalysisCorrection::query()->create([
                    'message_id' => $action->message_id,
                    'field' => 'draft_reply',
                    'ai_value' => $oldDraft,
                    'manager_value' => $draftReply,
                ]);

                $this->metrics->record(
                    'draft_edited',
                    ['field' => 'draft_reply'],
                    Message::query()->find($action->message_id),
                    projectId: $action->project_id,
                    managerActionId: $action->id,
                );
            }
        });
    }

    /**
     * Reject the suggested action. Records hypothesis metrics when possible.
     */
    public function reject(ManagerAction $action, ?string $notes = null): void
    {
        $this->assertTransitionable(
            $action,
            [ManagerActionStatus::Pending, ManagerActionStatus::Edited],
            'reject',
        );

        DB::transaction(function () use ($action, $notes): void {
            $action->fill([
                'status' => ManagerActionStatus::Rejected,
                'manager_notes' => $notes,
                'decided_at' => now(),
            ]);
            $action->save();

            if ($action->message_id !== null) {
                AnalysisCorrection::query()->create([
                    'message_id' => $action->message_id,
                    'field' => 'suggested_action',
                    'ai_value' => $action->action_type->value,
                    'manager_value' => 'rejected',
                ]);

                AnalysisCorrection::query()->create([
                    'message_id' => $action->message_id,
                    'field' => 'requires_manager',
                    'ai_value' => 'true',
                    'manager_value' => 'false',
                ]);
            }

            $this->metrics->record(
                'action_rejected',
                [
                    'action_type' => $action->action_type->value,
                    'notes' => $notes,
                ],
                $action->message_id !== null ? Message::query()->find($action->message_id) : null,
                projectId: $action->project_id,
                managerActionId: $action->id,
            );
        });
    }

    /**
     * Mark as completed after the manager handled the item manually (no auto-send).
     */
    public function complete(ManagerAction $action): void
    {
        $this->assertTransitionable(
            $action,
            [ManagerActionStatus::Approved, ManagerActionStatus::Edited],
            'complete',
        );

        $action->fill([
            'status' => ManagerActionStatus::Completed,
            'decided_at' => $action->decided_at ?? now(),
        ]);
        $action->save();
    }

    /**
     * @param  list<ManagerActionStatus>  $allowed
     */
    private function assertTransitionable(ManagerAction $action, array $allowed, string $operation): void
    {
        if (! in_array($action->status, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => sprintf(
                    'Cannot %s manager action with status "%s".',
                    $operation,
                    $action->status->value,
                ),
            ]);
        }
    }
}
