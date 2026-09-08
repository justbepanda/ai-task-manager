<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Executor;

use App\Domain\Executors\ExecutorService;
use App\Models\Executor;
use App\Orchid\Layouts\Executor\ExecutorEditLayout;
use Illuminate\Http\Request;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Screen;
use Orchid\Support\Color;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;

class ExecutorEditScreen extends Screen
{
    public ?Executor $executor = null;

    public function query(Executor $executor): iterable
    {
        $this->executor = $executor;
        $executor->load('projects');

        $service = app(ExecutorService::class);
        if (! $executor->exists) {
            $executor->is_active = true;
        }

        $executor->setAttribute('emails', $service->listToLines($executor->emails));
        $executor->setAttribute('project_ids', $executor->projects->pluck('id')->all());

        return [
            'executor' => $executor,
        ];
    }

    public function name(): ?string
    {
        return $this->executor?->exists ? 'Редактирование исполнителя' : 'Новый исполнитель';
    }

    public function description(): ?string
    {
        return $this->executor?->exists
            ? $this->executor->fullName()
            : 'Сотрудник нашей компании.';
    }

    public function commandBar(): iterable
    {
        return [
            Link::make('К списку')->icon('bs.arrow-left')->route('platform.executors'),
            Button::make('Сохранить')
                ->icon('bs.check-circle')
                ->method('save')
                ->type(Color::PRIMARY),
            Button::make('Удалить')
                ->icon('bs.trash')
                ->method('remove')
                ->type(Color::DANGER)
                ->canSee((bool) $this->executor?->exists)
                ->confirm('Удалить исполнителя?'),
        ];
    }

    public function layout(): iterable
    {
        return [
            Layout::block(ExecutorEditLayout::class)
                ->title('Данные исполнителя')
                ->description('Исполнители связываются с проектами независимо от клиентов.'),
        ];
    }

    public function save(Request $request, Executor $executor, ExecutorService $executors)
    {
        $validated = $request->validate([
            'executor.last_name' => ['required', 'string', 'max:255'],
            'executor.first_name' => ['required', 'string', 'max:255'],
            'executor.middle_name' => ['nullable', 'string', 'max:255'],
            'executor.position' => ['nullable', 'string', 'max:255'],
            'executor.emails' => ['nullable', 'string'],
            'executor.telegram' => ['nullable', 'string', 'max:255'],
            'executor.phone' => ['nullable', 'string', 'max:255'],
            'executor.notes' => ['nullable', 'string'],
            'executor.is_active' => ['nullable', 'boolean'],
            'executor.project_ids' => ['nullable', 'array'],
            'executor.project_ids.*' => ['integer', 'exists:projects,id'],
        ]);

        $payload = $validated['executor'];
        $payload['project_ids'] = $payload['project_ids'] ?? [];
        $payload['emails'] = $executors->linesToList($payload['emails'] ?? null);
        $payload['is_active'] = (bool) ($payload['is_active'] ?? false);

        if ($executor->exists) {
            $executors->update($executor, $payload);
            Toast::info('Исполнитель обновлён.');

            return;
        }

        $created = $executors->create($payload);
        Toast::info('Исполнитель создан.');

        return redirect()->route('platform.executors.edit', $created);
    }

    public function remove(Executor $executor, ExecutorService $executors)
    {
        if (! $executor->exists) {
            return redirect()->route('platform.executors');
        }

        $executors->delete($executor);
        Toast::info('Исполнитель удалён.');

        return redirect()->route('platform.executors');
    }
}
