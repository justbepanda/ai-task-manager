<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Project;

use App\Domain\Projects\ProjectService;
use App\Enums\ProjectStatus;
use App\Models\ClientCompany;
use App\Models\ClientContact;
use App\Models\Event;
use App\Models\Executor;
use App\Models\Message;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Orchid\Layouts\Project\ProjectEditLayout;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Screen;
use Orchid\Screen\Sight;
use Orchid\Screen\TD;
use Orchid\Support\Color;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;
use Illuminate\Support\Str;

class ProjectEditScreen extends Screen
{
    public ?Project $project = null;

    public bool $hasAgentState = false;

    public function query(Project $project): iterable
    {
        $this->project = $project;
        $project->load(['instruction', 'matchingRule', 'clientCompanies', 'clientContacts.clientCompany', 'tasks.managerExecutor', 'tasks.executors']);

        $service = app(ProjectService::class);
        $rule = $project->matchingRule;

        if (! $project->exists) {
            $project->status = ProjectStatus::Active;
        }

        $project->setAttribute('instruction_body', $project->instruction?->body);
        $project->setAttribute('client_emails', $service->listToLines($rule?->client_emails));
        $project->setAttribute('staff_emails', $service->listToLines($rule?->staff_emails));
        $project->setAttribute('domains', $service->listToLines($rule?->domains));
        $project->setAttribute('subject_patterns', $service->listToLines($rule?->subject_patterns));
        $project->setAttribute('keywords', $service->listToLines($rule?->keywords));
        $project->setAttribute('technical_sources', $service->listToLines($rule?->technical_sources));
        $project->setAttribute('custom_rules', $service->listToLines($rule?->custom_rules));

        $agentState = is_array($project->agent_state) ? $project->agent_state : [];
        $this->hasAgentState = $agentState !== [];

        return [
            'project' => $project,
            'clientCompanies' => $project->exists ? $project->clientCompanies : collect(),
            'clientContacts' => $project->exists ? $project->clientContacts : collect(),
            'taskManagers' => $project->exists ? $project->taskManagers() : collect(),
            'taskExecutors' => $project->exists ? $project->taskExecutors() : collect(),
            'messages' => $project->exists
                ? Message::query()->where('project_id', $project->id)->latest('received_at')->limit(20)->get()
                : collect(),
            'tasks' => $project->exists
                ? ProjectTask::query()
                    ->where('project_id', $project->id)
                    ->with(['managerExecutor', 'executors'])
                    ->withCount('messages')
                    ->orderByDesc('last_message_at')
                    ->orderByDesc('id')
                    ->limit(50)
                    ->get()
                : collect(),
            'events' => $project->exists
                ? Event::query()->where('project_id', $project->id)->latest()->limit(20)->get()
                : collect(),
        ];
    }

    public function name(): ?string
    {
        return $this->project?->exists ? 'Редактирование проекта' : 'Новый проект';
    }

    public function description(): ?string
    {
        return $this->project?->exists
            ? (string) $this->project->name
            : 'Создание проекта с правилами сопоставления.';
    }

    public function commandBar(): iterable
    {
        return [
            Link::make('К списку')->icon('bs.arrow-left')->route('platform.projects'),
            Button::make('Сохранить')
                ->icon('bs.check-circle')
                ->method('save')
                ->type(Color::PRIMARY),
            Button::make('Удалить')
                ->icon('bs.trash')
                ->method('remove')
                ->type(Color::DANGER)
                ->canSee((bool) $this->project?->exists)
                ->confirm('Удалить проект?'),
        ];
    }

    public function layout(): iterable
    {
        $projectExists = (bool) $this->project?->exists;
        $projectId = $this->project?->id;
        $firstCompany = $projectExists
            ? $this->project->clientCompanies->first()
            : null;

        return [
            Layout::block(ProjectEditLayout::class)
                ->title('Основные данные и правила')
                ->description('Инструкция AI и детерминированные правила хранятся отдельно.')
                ->vertical(),

            ...$this->agentStateLayout(),

            Layout::block([
                Layout::table('clientCompanies', [
                    TD::make('name', 'Клиент')
                        ->render(fn (ClientCompany $company) => Link::make($company->name)
                            ->route('platform.client-companies.edit', $company)),
                ]),
            ])
                ->title('Клиенты')
                ->description('Компании-клиенты, связанные с проектом.')
                ->vertical()
                ->commands([
                    Link::make('Добавить клиента')
                        ->icon('bs.plus-circle')
                        ->href($projectExists
                            ? route('platform.client-companies.create', ['project_id' => $projectId])
                            : route('platform.client-companies.create'))
                        ->canSee($projectExists),
                ]),

            Layout::block([
                Layout::table('clientContacts', [
                    TD::make('full_name', 'Контакт')
                        ->render(fn (ClientContact $contact) => Link::make($contact->fullName())
                            ->route('platform.client-contacts.edit', $contact)),
                    TD::make('company', 'Клиент')
                        ->render(fn (ClientContact $contact) => e($contact->clientCompany?->name ?? '—')),
                    TD::make('position', 'Должность'),
                ]),
            ])
                ->title('Контакты')
                ->description($firstCompany === null
                    ? 'Сначала добавьте клиента проекта — затем можно создать контакт.'
                    : 'Контакты компаний, привязанные к проекту.')
                ->vertical()
                ->commands(array_values(array_filter([
                    $firstCompany === null
                        ? Link::make('Добавить клиента')
                            ->icon('bs.plus-circle')
                            ->href(route('platform.client-companies.create', ['project_id' => $projectId]))
                            ->canSee($projectExists)
                        : Link::make('Добавить контакт')
                            ->icon('bs.person-plus')
                            ->href(route('platform.client-contacts.create', [
                                'company' => $firstCompany,
                                'project_id' => $projectId,
                            ]))
                            ->canSee($projectExists),
                ]))),

            Layout::block([
                Layout::table('taskManagers', [
                    TD::make('full_name', 'Менеджер')
                        ->render(fn (Executor $executor) => Link::make($executor->fullName())
                            ->route('platform.executors.edit', $executor)),
                    TD::make('position', 'Должность'),
                ]),
            ])
                ->title('Менеджеры задач')
                ->description('Собираются с задач проекта (поле «Автор» в Redmine / менеджер задачи). Назначаются на карточке задачи.')
                ->vertical(),

            Layout::block([
                Layout::table('taskExecutors', [
                    TD::make('full_name', 'Исполнитель')
                        ->render(fn (Executor $executor) => Link::make($executor->fullName())
                            ->route('platform.executors.edit', $executor)),
                    TD::make('position', 'Должность'),
                    TD::make('is_active', 'Активен')
                        ->render(fn (Executor $executor) => $executor->is_active ? 'Да' : 'Нет'),
                ]),
            ])
                ->title('Исполнители задач')
                ->description('Собираются с задач проекта. Назначаются на карточке задачи.')
                ->vertical(),

            Layout::block([
                Layout::table('tasks', [
                    TD::make('external_key', 'Ключ')
                        ->render(fn (ProjectTask $task) => e($task->source->label().': '.$task->external_key)),
                    TD::make('title', 'Задача')
                        ->render(fn (ProjectTask $task) => Link::make(Str::limit((string) ($task->title ?: $task->displayLabel()), 80))
                            ->route('platform.tasks.view', $task)),
                    TD::make('manager', 'Менеджер')
                        ->render(fn (ProjectTask $task) => e($task->managerExecutor?->fullName() ?? '—')),
                    TD::make('status', 'Статус')
                        ->render(fn (ProjectTask $task) => e($task->status->label())),
                    TD::make('messages_count', 'Писем')
                        ->render(fn (ProjectTask $task) => e((string) ($task->messages_count ?? 0))),
                    TD::make('last_message_at', 'Последнее')
                        ->render(fn (ProjectTask $task) => e($task->last_message_at?->format('d.m.Y H:i') ?? '—')),
                    TD::make('open', '')
                        ->render(fn (ProjectTask $task) => Link::make('Открыть')
                            ->route('platform.tasks.view', $task)),
                ]),
            ])
                ->title('Задачи проекта')
                ->vertical()
                ->commands([
                    Link::make('Все задачи')
                        ->icon('bs.list-task')
                        ->route('platform.tasks', ['filter' => ['project_id' => $projectId]])
                        ->canSee($projectExists),
                ]),

            Layout::block([
                Layout::table('messages', [
                    TD::make('id', 'ID')->width('70')
                        ->render(fn (Message $m) => Link::make((string) $m->id)
                            ->route('platform.messages.view', $m)),
                    TD::make('sender', 'Отправитель'),
                    TD::make('subject', 'Тема')
                        ->render(fn (Message $m) => Link::make(Str::limit((string) ($m->subject ?: '—'), 70))
                            ->route('platform.messages.view', $m)),
                    TD::make('classification', 'Классификация')
                        ->render(fn (Message $m) => e($m->classification?->label() ?? '—')),
                    TD::make('open', '')
                        ->render(fn (Message $m) => Link::make('Открыть')->route('platform.messages.view', $m)),
                ]),
            ])
                ->title('Связанные сообщения')
                ->vertical()
                ->commands([
                    Link::make('Все входящие')
                        ->icon('bs.inbox')
                        ->route('platform.messages', ['filter' => ['project_id' => $projectId]])
                        ->canSee($projectExists),
                ]),

            Layout::block([
                Layout::table('events', [
                    TD::make('id', 'ID')->width('70')
                        ->render(function (Event $e) {
                            if ($e->message_id === null) {
                                return e((string) $e->id);
                            }

                            return Link::make((string) $e->id)
                                ->route('platform.messages.view', $e->message_id);
                        }),
                    TD::make('type', 'Тип')->render(function (Event $e) {
                        $label = $e->type->label();
                        if ($e->message_id === null) {
                            return e($label);
                        }

                        return Link::make($label)
                            ->route('platform.messages.view', $e->message_id);
                    }),
                    TD::make('summary', 'Краткое описание')
                        ->render(function (Event $e) {
                            $summary = Str::limit((string) ($e->summary ?: '—'), 80);
                            if ($e->message_id === null) {
                                return e($summary);
                            }

                            return Link::make($summary)
                                ->route('platform.messages.view', $e->message_id);
                        }),
                    TD::make('confidence', 'Уверенность'),
                ]),
            ])
                ->title('События проекта')
                ->description('События создаются при обработке писем (Hermes).')
                ->vertical(),
        ];
    }

    /**
     * @return list<\Orchid\Screen\Layout>
     */
    private function agentStateLayout(): array
    {
        if (! $this->hasAgentState) {
            return [];
        }

        return [
            Layout::legend('project', [
                Sight::make('agent_topic', 'Тема')->render(function (Project $project): string {
                    $state = is_array($project->agent_state) ? $project->agent_state : [];

                    return e(trim((string) ($state['topic'] ?? '')) ?: '—');
                }),
                Sight::make('agent_waiting_for', 'Ожидаем')->render(function (Project $project): string {
                    $state = is_array($project->agent_state) ? $project->agent_state : [];

                    return e(trim((string) ($state['waiting_for'] ?? '')) ?: '—');
                }),
                Sight::make('agent_open_questions', 'Открытые вопросы')->render(function (Project $project): string {
                    $state = is_array($project->agent_state) ? $project->agent_state : [];
                    $open = $state['open_questions'] ?? [];
                    if (! is_array($open) || $open === []) {
                        return '—';
                    }

                    return e(implode('; ', array_map(static fn ($q) => (string) $q, $open)));
                }),
                Sight::make('agent_last_summary', 'Последнее summary')->render(function (Project $project): string {
                    $state = is_array($project->agent_state) ? $project->agent_state : [];

                    return e(trim((string) ($state['last_summary'] ?? '')) ?: '—');
                }),
            ])
                ->title('Память агента (проект)'),
        ];
    }

    public function save(Request $request, Project $project, ProjectService $projects)
    {
        $validated = $request->validate([
            'project.name' => ['required', 'string', 'max:255'],
            'project.description' => ['nullable', 'string'],
            'project.status' => ['required', Rule::enum(ProjectStatus::class)],
            'project.instruction_body' => ['nullable', 'string'],
            'project.client_emails' => ['nullable', 'string'],
            'project.staff_emails' => ['nullable', 'string'],
            'project.domains' => ['nullable', 'string'],
            'project.subject_patterns' => ['nullable', 'string'],
            'project.keywords' => ['nullable', 'string'],
            'project.technical_sources' => ['nullable', 'string'],
            'project.custom_rules' => ['nullable', 'string'],
        ]);

        $payload = $validated['project'];

        if ($project->exists) {
            $projects->update($project, $payload);
            Toast::info('Проект обновлён.');

            return;
        }

        $created = $projects->create($payload);
        Toast::info('Проект создан.');

        return redirect()->route('platform.projects.edit', $created);
    }

    public function remove(Project $project, ProjectService $projects)
    {
        if (! $project->exists) {
            return redirect()->route('platform.projects');
        }

        $projects->delete($project);
        Toast::info('Проект удалён.');

        return redirect()->route('platform.projects');
    }
}
