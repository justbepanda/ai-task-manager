<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Task;

use App\Models\Message;
use App\Models\ProjectTask;
use Illuminate\Support\Str;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Screen;
use Orchid\Screen\Sight;
use Orchid\Screen\TD;
use Orchid\Support\Facades\Layout;

class ProjectTaskViewScreen extends Screen
{
    public ?ProjectTask $task = null;

    public function query(ProjectTask $task): iterable
    {
        $task->load(['project']);
        $task->loadCount('messages');
        $this->task = $task;

        return [
            'task' => $task,
            'messages' => Message::query()
                ->where('project_task_id', $task->id)
                ->latest('received_at')
                ->limit(50)
                ->get(),
        ];
    }

    public function name(): ?string
    {
        return $this->task?->displayLabel() ?? 'Задача';
    }

    public function description(): ?string
    {
        return 'Карточка задачи и связанные письма.';
    }

    public function commandBar(): iterable
    {
        $items = [
            Link::make('К списку')
                ->icon('bs.arrow-left')
                ->route('platform.tasks'),
            Link::make('Все письма')
                ->icon('bs.inbox')
                ->route('platform.messages', [
                    'filter' => ['project_task_id' => $this->task?->id],
                ]),
        ];

        if ($this->task?->project !== null) {
            $items[] = Link::make('Проект')
                ->icon('bs.folder2')
                ->route('platform.projects.edit', $this->task->project);
        }

        return $items;
    }

    public function layout(): iterable
    {
        return [
            Layout::legend('task', [
                Sight::make('id', 'ID'),
                Sight::make('source', 'Источник')
                    ->render(fn (ProjectTask $task) => e($task->source->label())),
                Sight::make('external_key', 'Ключ'),
                Sight::make('title', 'Название')
                    ->render(fn (ProjectTask $task) => e((string) ($task->title ?: '—'))),
                Sight::make('status', 'Статус')
                    ->render(fn (ProjectTask $task) => e($task->status->label())),
                Sight::make('project', 'Проект')
                    ->render(function (ProjectTask $task) {
                        if ($task->project === null) {
                            return '—';
                        }

                        return Link::make($task->project->name)
                            ->route('platform.projects.edit', $task->project);
                    }),
                Sight::make('messages_count', 'Писем')
                    ->render(fn (ProjectTask $task) => e((string) ($task->messages_count ?? 0))),
                Sight::make('last_message_at', 'Последнее письмо')
                    ->render(fn (ProjectTask $task) => e($task->last_message_at?->format('d.m.Y H:i') ?? '—')),
            ])->title('Задача'),

            Layout::block([
                Layout::table('messages', [
                    TD::make('received_at', 'Дата')
                        ->render(fn (Message $message) => e($message->received_at?->format('d.m.Y H:i') ?? '—')),
                    TD::make('sender', 'От')
                        ->render(fn (Message $message) => e(Str::limit((string) $message->sender, 40))),
                    TD::make('subject', 'Тема')
                        ->render(fn (Message $message) => Link::make(Str::limit((string) ($message->subject ?: '—'), 70))
                            ->route('platform.messages.view', $message)),
                    TD::make('classification', 'Классификация')
                        ->render(fn (Message $message) => e($message->classification?->label() ?? '—')),
                ]),
            ])
                ->title('Письма задачи (до 50)')
                ->vertical()
                ->commands([
                    Link::make('Все письма')
                        ->icon('bs.inbox')
                        ->route('platform.messages', [
                            'filter' => ['project_task_id' => $this->task?->id],
                        ]),
                ]),
        ];
    }
}
