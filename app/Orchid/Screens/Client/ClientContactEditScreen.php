<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Client;

use App\Domain\Clients\ClientContactService;
use App\Models\ClientCompany;
use App\Models\ClientContact;
use App\Models\Project;
use App\Orchid\Layouts\Client\ClientContactEditLayout;
use Illuminate\Http\Request;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Screen;
use Orchid\Support\Color;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;

class ClientContactEditScreen extends Screen
{
    public ?ClientContact $contact = null;

    public ?ClientCompany $company = null;

    public function query(?ClientCompany $company = null, ?ClientContact $contact = null): iterable
    {
        if ($contact !== null && $contact->exists) {
            $this->contact = $contact->load(['clientCompany', 'projects']);
            $this->company = $this->contact->clientCompany;
        } else {
            abort_unless($company !== null && $company->exists, 404);
            $this->company = $company;
            $this->contact = new ClientContact([
                'client_company_id' => $company->id,
            ]);
        }

        $service = app(ClientContactService::class);
        $this->contact->setAttribute('emails', $service->listToLines($this->contact->emails));
        $this->contact->setAttribute(
            'project_ids',
            $this->contact->exists
                ? $this->contact->projects->pluck('id')->all()
                : $this->requestedProjectIds()
        );

        return [
            'contact' => $this->contact,
            'company' => $this->company,
            'company_name' => $this->company?->name,
        ];
    }

    public function name(): ?string
    {
        return $this->contact?->exists ? 'Редактирование контакта' : 'Новый контакт';
    }

    public function description(): ?string
    {
        return $this->company?->name ?? 'Контакт клиента';
    }

    public function commandBar(): iterable
    {
        return [
            Link::make('К клиенту')
                ->icon('bs.arrow-left')
                ->route('platform.client-companies.edit', $this->company),
            Button::make('Сохранить')
                ->icon('bs.check-circle')
                ->method('save')
                ->type(Color::PRIMARY),
            Button::make('Удалить')
                ->icon('bs.trash')
                ->method('remove')
                ->type(Color::DANGER)
                ->canSee((bool) $this->contact?->exists)
                ->confirm('Удалить контакт?'),
        ];
    }

    public function layout(): iterable
    {
        return [
            Layout::block(ClientContactEditLayout::class)
                ->title('Данные контакта')
                ->description('Контакт всегда принадлежит одной компании.')
                ->vertical(),
        ];
    }

    public function save(Request $request, ClientContactService $contacts)
    {
        $validated = $request->validate([
            'contact.last_name' => ['required', 'string', 'max:255'],
            'contact.first_name' => ['required', 'string', 'max:255'],
            'contact.middle_name' => ['nullable', 'string', 'max:255'],
            'contact.position' => ['nullable', 'string', 'max:255'],
            'contact.emails' => ['nullable', 'string'],
            'contact.telegram' => ['nullable', 'string', 'max:255'],
            'contact.phone' => ['nullable', 'string', 'max:255'],
            'contact.notes' => ['nullable', 'string'],
            'contact.project_ids' => ['nullable', 'array'],
            'contact.project_ids.*' => ['integer', 'exists:projects,id'],
        ]);

        $payload = $validated['contact'];
        $payload['project_ids'] = $payload['project_ids'] ?? [];
        $payload['emails'] = $contacts->linesToList($payload['emails'] ?? null);

        if ($this->contact?->exists) {
            $contacts->update($this->contact, $payload);
            Toast::info('Контакт обновлён.');

            return;
        }

        abort_unless($this->company?->exists, 404);

        $created = $contacts->create($this->company, $payload);
        Toast::info('Контакт создан.');

        return redirect()->route('platform.client-contacts.edit', $created);
    }

    public function remove(ClientContactService $contacts)
    {
        if (! $this->contact?->exists) {
            return redirect()->route('platform.client-companies');
        }

        $company = $this->contact->clientCompany;
        $contacts->delete($this->contact);
        Toast::info('Контакт удалён.');

        return redirect()->route('platform.client-companies.edit', $company);
    }

    /**
     * @return list<int>
     */
    private function requestedProjectIds(): array
    {
        $projectId = (int) request()->query('project_id', 0);
        if ($projectId <= 0) {
            return [];
        }

        return Project::query()->whereKey($projectId)->exists()
            ? [$projectId]
            : [];
    }
}
