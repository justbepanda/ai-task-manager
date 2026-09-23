<?php

declare(strict_types=1);

namespace App\Orchid\Screens\ManagerAction;

use App\Domain\ManagerActions\ManagerActionService;
use App\Enums\ManagerActionStatus;
use App\Models\ManagerAction;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Fields\TextArea;
use Orchid\Screen\Screen;
use Orchid\Screen\Sight;
use Orchid\Support\Color;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;

class ManagerActionViewScreen extends Screen
{
    public ?ManagerAction $action = null;

    public function query(ManagerAction $manager_action): iterable
    {
        $manager_action->load(['project', 'event', 'message.projectTask']);

        $this->action = $manager_action;

        return [
            'action' => $manager_action,
            'form' => [
                'draft_reply' => $manager_action->draft_reply,
                'manager_notes' => $manager_action->manager_notes,
                'final_reply' => $manager_action->final_reply ?? $manager_action->draft_reply,
            ],
        ];
    }

    public function name(): ?string
    {
        return 'Действие #'.($this->action?->id ?? '');
    }

    public function description(): ?string
    {
        return $this->action?->reason;
    }

    public function commandBar(): iterable
    {
        $status = $this->action?->status;

        return [
            Link::make('К списку')->icon('bs.arrow-left')->route('platform.manager-actions'),

            Button::make('Подтвердить')
                ->icon('bs.check-circle')
                ->method('approve')
                ->type(Color::SUCCESS)
                ->canSee(in_array($status, [ManagerActionStatus::Pending, ManagerActionStatus::Edited], true)),

            Button::make('Сохранить черновик')
                ->icon('bs.pencil')
                ->method('editDraft')
                ->type(Color::PRIMARY)
                ->canSee(in_array($status, [ManagerActionStatus::Pending, ManagerActionStatus::Edited], true)),

            Button::make('Отклонить')
                ->icon('bs.x-circle')
                ->method('reject')
                ->type(Color::DANGER)
                ->canSee(in_array($status, [ManagerActionStatus::Pending, ManagerActionStatus::Edited], true))
                ->confirm('Отклонить предложение?'),

            Button::make('Завершить')
                ->icon('bs.flag')
                ->method('complete')
                ->type(Color::SECONDARY)
                ->canSee(in_array($status, [ManagerActionStatus::Approved, ManagerActionStatus::Edited], true)),
        ];
    }

    public function layout(): iterable
    {
        return [
            Layout::legend('action', [
                Sight::make('project', 'Проект')->render(function (ManagerAction $a) {
                    if ($a->project === null) {
                        return '—';
                    }

                    return Link::make($a->project->name)
                        ->route('platform.projects.edit', $a->project);
                }),
                Sight::make('project_task', 'Задача')->render(function (ManagerAction $a) {
                    $task = $a->message?->projectTask;
                    if ($task === null) {
                        return '—';
                    }

                    return Link::make($task->displayLabel())
                        ->route('platform.tasks.view', $task);
                }),
                Sight::make('event', 'Событие')->render(function (ManagerAction $a) {
                    $event = $a->event;
                    if ($event === null) {
                        return '—';
                    }

                    $label = ($event->type?->label() ?? '—').': '.($event->summary ?? '');
                    $messageId = $a->message_id ?? $event->message_id;
                    if ($messageId === null) {
                        return e($label);
                    }

                    return Link::make($label)
                        ->route('platform.messages.view', $messageId);
                }),
                Sight::make('action_type', 'Предлагаемое действие')->render(fn (ManagerAction $a) => e($a->action_type->label())),
                Sight::make('status', 'Статус')->render(fn (ManagerAction $a) => e($a->status->label())),
                Sight::make('reason', 'Почему требуется действие'),
                Sight::make('message', 'Письмо')->render(fn (ManagerAction $a) => $a->message_id
                    ? Link::make('#'.$a->message_id.' '.$a->message?->subject)->route('platform.messages.view', $a->message_id)
                    : '—'),
            ]),

            Layout::rows([
                TextArea::make('form.draft_reply')
                    ->title('Черновик ответа')
                    ->rows(10),
                TextArea::make('form.final_reply')
                    ->title('Финальный ответ (для подтверждения)')
                    ->rows(6)
                    ->help('MVP не отправляет письмо — только фиксирует решение.'),
                TextArea::make('form.manager_notes')
                    ->title('Заметки менеджера')
                    ->rows(3),
            ]),
        ];
    }

    public function approve(Request $request, ManagerAction $manager_action, ManagerActionService $service): void
    {
        try {
            $service->approve(
                $manager_action,
                $request->input('form.final_reply'),
            );
            Toast::info('Предложение подтверждено. Письмо не отправлено (MVP).');
        } catch (ValidationException $e) {
            Toast::error($e->getMessage());
        }
    }

    public function editDraft(Request $request, ManagerAction $manager_action, ManagerActionService $service): void
    {
        $validated = $request->validate([
            'form.draft_reply' => ['required', 'string'],
            'form.manager_notes' => ['nullable', 'string'],
        ]);

        try {
            $service->edit(
                $manager_action,
                $validated['form']['draft_reply'],
                $validated['form']['manager_notes'] ?? null,
            );
            Toast::info('Черновик обновлён.');
        } catch (ValidationException $e) {
            Toast::error($e->getMessage());
        }
    }

    public function reject(Request $request, ManagerAction $manager_action, ManagerActionService $service): void
    {
        try {
            $service->reject(
                $manager_action,
                $request->input('form.manager_notes'),
            );
            Toast::info('Предложение отклонено.');
        } catch (ValidationException $e) {
            Toast::error($e->getMessage());
        }
    }

    public function complete(ManagerAction $manager_action, ManagerActionService $service): void
    {
        try {
            $service->complete($manager_action);
            Toast::info('Действие отмечено как выполненное.');
        } catch (ValidationException $e) {
            Toast::error($e->getMessage());
        }
    }
}
