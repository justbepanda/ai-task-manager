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
use App\Orchid\Layouts\Project\ProjectEditLayout;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Screen;
use Orchid\Screen\TD;
use Orchid\Support\Color;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;

class ProjectEditScreen extends Screen
{
    public ?Project $project = null;

    public function query(Project $project): iterable
    {
        $this->project = $project;
        $project->load(['instruction', 'matchingRule', 'clientCompanies', 'clientContacts.clientCompany', 'executors']);

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

        return [
            'project' => $project,
            'clientCompanies' => $project->exists ? $project->clientCompanies : collect(),
            'clientContacts' => $project->exists ? $project->clientContacts : collect(),
            'executors' => $project->exists ? $project->executors : collect(),
            'messages' => $project->exists
                ? Message::query()->where('project_id', $project->id)->latest('received_at')->limit(20)->get()
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
        return [
            Layout::block(ProjectEditLayout::class)
                ->title('Основные данные и правила')
                ->description('Инструкция AI и детерминированные правила хранятся отдельно.'),

            Layout::table('clientCompanies', [
                TD::make('name', 'Клиент')
                    ->render(fn (ClientCompany $company) => Link::make($company->name)
                        ->route('platform.client-companies.edit', $company)),
            ])->title('Клиенты'),

            Layout::table('clientContacts', [
                TD::make('full_name', 'Контакт')
                    ->render(fn (ClientContact $contact) => Link::make($contact->fullName())
                        ->route('platform.client-contacts.edit', $contact)),
                TD::make('company', 'Клиент')
                    ->render(fn (ClientContact $contact) => e($contact->clientCompany?->name ?? '—')),
                TD::make('position', 'Должность'),
            ])->title('Контакты'),

            Layout::table('executors', [
                TD::make('full_name', 'Исполнитель')
                    ->render(fn (Executor $executor) => Link::make($executor->fullName())
                        ->route('platform.executors.edit', $executor)),
                TD::make('position', 'Должность'),
                TD::make('is_active', 'Активен')
                    ->render(fn (Executor $executor) => $executor->is_active ? 'Да' : 'Нет'),
            ])->title('Исполнители'),

            Layout::table('messages', [
                TD::make('id', 'ID')->width('70'),
                TD::make('sender', 'Отправитель'),
                TD::make('subject', 'Тема'),
                TD::make('classification', 'Классификация')
                    ->render(fn (Message $m) => e($m->classification?->label() ?? '—')),
                TD::make('open', '')
                    ->render(fn (Message $m) => Link::make('Открыть')->route('platform.messages.view', $m)),
            ])->title('Связанные сообщения'),

            Layout::table('events', [
                TD::make('id', 'ID')->width('70'),
                TD::make('type', 'Тип')->render(fn (Event $e) => e($e->type->label())),
                TD::make('summary', 'Краткое описание'),
                TD::make('confidence', 'Уверенность'),
            ])->title('События проекта'),
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
