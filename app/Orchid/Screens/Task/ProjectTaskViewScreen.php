<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Task;

use App\Domain\Tasks\ProjectTaskPeopleService;
use App\Models\Executor;
use App\Models\Message;
use App\Models\ProjectTask;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Fields\Select;
use Orchid\Screen\Screen;
use Orchid\Screen\Sight;
use Orchid\Screen\TD;
use Orchid\Support\Color;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;

class ProjectTaskViewScreen extends Screen
{
    public ?ProjectTask $task = null;

    public function query(ProjectTask $task): iterable
    {
        $task->load(['project', 'managerExecutor', 'executors']);
        $task->loadCount('messages');
        $this->task = $task;

        return [
            'task' => $task,
            'people' => [
                'manager_executor_id' => $task->manager_executor_id,
                'executor_ids' => $task->executors->pluck('id')->all(),
            ],
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
        return 'Карточка задачи, люди и связанные письма.';
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
            Button::make('Сохранить людей')
                ->icon('bs.check-circle')
                ->method('savePeople')
                ->type(Color::PRIMARY),
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
        $executorOptions = Executor::query()
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get()
            ->mapWithKeys(static fn (Executor $e): array => [(string) $e->id => $e->fullName()])
            ->all();

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
                Sight::make('manager', 'Менеджер')
                    ->render(fn (ProjectTask $task) => $task->managerExecutor === null
                        ? '—'
                        : Link::make($task->managerExecutor->fullName())
                            ->route('platform.executors.edit', $task->managerExecutor)),
                Sight::make('executors', 'Исполнители')
                    ->render(function (ProjectTask $task) {
                        if ($task->executors->isEmpty()) {
                            return '—';
                        }

                        return $task->executors->map(static function (Executor $e): string {
                            return '<a href="'.e(route('platform.executors.edit', $e)).'">'
                                .e($e->fullName()).'</a>';
                        })->implode(', ');
                    }),
                Sight::make('messages_count', 'Писем')
                    ->render(fn (ProjectTask $task) => e((string) ($task->messages_count ?? 0))),
                Sight::make('last_message_at', 'Последнее письмо')
                    ->render(fn (ProjectTask $task) => e($task->last_message_at?->format('d.m.Y H:i') ?? '—')),
            ])->title('Задача'),

            Layout::rows([
                Select::make('people.manager_executor_id')
                    ->title('Менеджер задачи')
                    ->options($executorOptions)
                    ->empty('Не выбран')
                    ->help('Обычно «Автор» из Redmine.'),
                Select::make('people.executor_ids')
                    ->title('Исполнители задачи')
                    ->options($executorOptions)
                    ->multiple()
                    ->help('Обычно «Назначена» из Redmine и другие исполнители.'),
            ])->title('Люди на задаче'),

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

    public function savePeople(Request $request, ProjectTask $task, ProjectTaskPeopleService $service): void
    {
        $validated = $request->validate([
            'people.manager_executor_id' => ['nullable', 'integer', 'exists:executors,id'],
            'people.executor_ids' => ['nullable', 'array'],
            'people.executor_ids.*' => ['integer', 'exists:executors,id'],
        ]);

        $people = $validated['people'] ?? [];
        $managerId = isset($people['manager_executor_id']) && $people['manager_executor_id'] !== ''
            ? (int) $people['manager_executor_id']
            : null;

        $service->update(
            $task,
            $managerId,
            is_array($people['executor_ids'] ?? null) ? $people['executor_ids'] : [],
        );

        Toast::info('Менеджер и исполнители задачи сохранены.');
    }
}
