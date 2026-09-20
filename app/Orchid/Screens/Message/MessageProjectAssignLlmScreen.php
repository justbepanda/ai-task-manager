<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Message;

use App\AI\Exceptions\AnalysisException;
use App\Domain\Activity\ActivityLogRecorder;
use App\Domain\Projects\AssignmentBoardPlan;
use App\Domain\Projects\BulkAttachMessagesToProject;
use App\Domain\Projects\DTO\AssignmentBoard;
use App\Domain\Projects\SuggestUnassignedMessageAssignmentsWithLlm;
use Illuminate\Http\Request;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Screen;
use Orchid\Support\Color;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;

class MessageProjectAssignLlmScreen extends Screen
{
    public ?AssignmentBoard $board = null;

    public bool $hasSuggestion = false;

    public function query(): iterable
    {
        $plan = session(AssignmentBoardPlan::SESSION_KEY);
        if (is_array($plan)) {
            $this->board = AssignmentBoardPlan::hydrate($plan);
            $this->hasSuggestion = true;
        } else {
            $this->board = new AssignmentBoard(groups: [], unmatched: []);
            $this->hasSuggestion = false;
        }

        return [
            'board' => $this->board,
            'assignMode' => 'llm',
            'hasSuggestion' => $this->hasSuggestion,
        ];
    }

    public function name(): ?string
    {
        return 'Распределение по проектам (LLM)';
    }

    public function description(): ?string
    {
        return 'Предложения Gemini/Hermes. Менеджер проверяет галочками и подтверждает — AI сам не привязывает.';
    }

    public function commandBar(): iterable
    {
        return [
            Button::make('Запросить предложение LLM')
                ->icon('bs.stars')
                ->method('requestSuggestion')
                ->type(Color::PRIMARY),
            Link::make('По правилам')
                ->icon('bs.diagram-3')
                ->route('platform.messages.assign'),
            Link::make('К входящим')
                ->icon('bs.arrow-left')
                ->route('platform.messages'),
        ];
    }

    public function layout(): iterable
    {
        return [
            Layout::view('orchid.messages.assign-board', [
                'assignMode' => 'llm',
                'hasSuggestion' => $this->hasSuggestion,
            ]),
        ];
    }

    public function requestSuggestion(
        SuggestUnassignedMessageAssignmentsWithLlm $suggest,
        ActivityLogRecorder $activity,
    ) {
        try {
            $board = $suggest->build();
        } catch (AnalysisException $e) {
            $activity->record(
                category: 'llm',
                action: 'assign_suggest',
                status: 'failed',
                summary: 'LLM распределение не удалось: '.$e->getMessage(),
                details: [
                    'error_type' => $e->errorType,
                    'exception' => $e::class,
                ],
            );

            Toast::error('LLM не смог предложить распределение: '.$e->getMessage());

            return redirect()->route('platform.messages.assign-llm');
        }

        session([AssignmentBoardPlan::SESSION_KEY => AssignmentBoardPlan::serialize($board)]);

        $groupCount = count($board->groups);
        $msgCount = array_sum(array_map(
            static fn ($g) => count($g->suggestions),
            $board->groups,
        ));
        $unmatchedCount = count($board->unmatched);

        $activity->record(
            category: 'llm',
            action: 'assign_suggest',
            status: 'ok',
            summary: "LLM распределение: групп {$groupCount}, писем в группах {$msgCount}, без проекта {$unmatchedCount}",
            details: [
                'groups' => $groupCount,
                'messages_in_groups' => $msgCount,
                'unmatched' => $unmatchedCount,
            ],
        );

        Toast::info("LLM предложил групп: {$groupCount}, писем в группах: {$msgCount}");

        return redirect()->route('platform.messages.assign-llm');
    }

    public function attachToProject(Request $request, BulkAttachMessagesToProject $bulk)
    {
        $validated = $request->validate([
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'message_ids' => ['required', 'array', 'min:1'],
            'message_ids.*' => ['integer', 'exists:messages,id'],
        ]);

        $messageIds = array_map('intval', $validated['message_ids']);
        $projectId = (int) $validated['project_id'];

        $count = $bulk->attach($projectId, $messageIds);

        $this->prunePlanAfterAttach($messageIds, $projectId, $count);

        Toast::info($count > 0
            ? "Привязано писем: {$count}"
            : 'Нечего привязывать (уже назначены или список пуст).');

        return redirect()->route('platform.messages.assign-llm');
    }

    public function createAndAttach(Request $request, BulkAttachMessagesToProject $bulk)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'message_ids' => ['required', 'array', 'min:1'],
            'message_ids.*' => ['integer', 'exists:messages,id'],
            'aliases' => ['sometimes', 'array'],
            'aliases.*' => ['string', 'max:255'],
        ]);

        $messageIds = array_map('intval', $validated['message_ids']);

        $result = $bulk->createProjectAndAttach(
            $validated['name'],
            $messageIds,
            array_values($validated['aliases'] ?? []),
        );

        $this->prunePlanAfterAttach(
            $messageIds,
            (int) $result['project']->id,
            (int) $result['attached'],
        );

        Toast::info(sprintf(
            'Проект «%s» создан, привязано писем: %d',
            $result['project']->name,
            $result['attached'],
        ));

        return redirect()->route('platform.messages.assign-llm');
    }

    /**
     * @param  list<int>  $messageIds
     */
    private function prunePlanAfterAttach(array $messageIds, int $projectId, int $attachedCount): void
    {
        $plan = session(AssignmentBoardPlan::SESSION_KEY);
        if (! is_array($plan)) {
            return;
        }

        $updated = AssignmentBoardPlan::withoutMessageIds(
            $plan,
            $messageIds,
            $projectId,
            $attachedCount,
        );

        if (AssignmentBoardPlan::isEmpty($updated)) {
            session()->forget(AssignmentBoardPlan::SESSION_KEY);

            return;
        }

        session([AssignmentBoardPlan::SESSION_KEY => $updated]);
    }
}
