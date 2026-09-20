<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Message;

use App\Domain\Projects\BulkAttachMessagesToProject;
use App\Domain\Projects\DTO\AssignmentBoard;
use App\Domain\Projects\SuggestUnassignedMessageAssignments;
use Illuminate\Http\Request;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;

class MessageProjectAssignScreen extends Screen
{
    public ?AssignmentBoard $board = null;

    public function query(SuggestUnassignedMessageAssignments $suggest): iterable
    {
        $this->board = $suggest->build();

        return [
            'board' => $this->board,
            'assignMode' => 'rules',
            'hasSuggestion' => true,
        ];
    }

    public function name(): ?string
    {
        return 'Распределение по проектам';
    }

    public function description(): ?string
    {
        return 'Предложения по правилам и теме Redmine.';
    }

    public function commandBar(): iterable
    {
        return [
            Link::make('Через LLM')
                ->icon('bs.stars')
                ->route('platform.messages.assign-llm'),
            Link::make('К входящим')
                ->icon('bs.arrow-left')
                ->route('platform.messages'),
        ];
    }

    public function layout(): iterable
    {
        return [
            Layout::view('orchid.messages.assign-board', [
                'assignMode' => 'rules',
                'hasSuggestion' => true,
            ]),
        ];
    }

    public function attachToProject(Request $request, BulkAttachMessagesToProject $bulk)
    {
        $validated = $request->validate([
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'message_ids' => ['required', 'array', 'min:1'],
            'message_ids.*' => ['integer', 'exists:messages,id'],
        ]);

        $count = $bulk->attach(
            (int) $validated['project_id'],
            array_map('intval', $validated['message_ids']),
        );

        Toast::info($count > 0
            ? "Привязано писем: {$count}"
            : 'Нечего привязывать (уже назначены или список пуст).');

        return redirect()->route('platform.messages.assign');
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

        $result = $bulk->createProjectAndAttach(
            $validated['name'],
            array_map('intval', $validated['message_ids']),
            array_values($validated['aliases'] ?? []),
        );

        Toast::info(sprintf(
            'Проект «%s» создан, привязано писем: %d',
            $result['project']->name,
            $result['attached'],
        ));

        return redirect()->route('platform.messages.assign');
    }
}
