<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Client;

use App\Domain\Clients\ClientCompanyService;
use App\Models\ClientCompany;
use App\Models\ClientContact;
use App\Orchid\Layouts\Client\ClientCompanyEditLayout;
use Illuminate\Http\Request;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Screen;
use Orchid\Screen\TD;
use Orchid\Support\Color;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;

class ClientCompanyEditScreen extends Screen
{
    public ?ClientCompany $company = null;

    public function query(ClientCompany $company): iterable
    {
        $this->company = $company;
        $company->load(['projects', 'contacts']);
        $company->setAttribute('project_ids', $company->projects->pluck('id')->all());

        return [
            'company' => $company,
            'contacts' => $company->exists
                ? $company->contacts()->orderBy('last_name')->orderBy('first_name')->get()
                : collect(),
        ];
    }

    public function name(): ?string
    {
        return $this->company?->exists ? 'Редактирование клиента' : 'Новый клиент';
    }

    public function description(): ?string
    {
        return $this->company?->exists
            ? (string) $this->company->name
            : 'Создание компании-клиента.';
    }

    public function commandBar(): iterable
    {
        $bar = [
            Link::make('К списку')->icon('bs.arrow-left')->route('platform.client-companies'),
        ];

        if ($this->company?->exists) {
            $bar[] = Link::make('Добавить контакт')
                ->icon('bs.person-plus')
                ->route('platform.client-contacts.create', $this->company);
        }

        $bar[] = Button::make('Сохранить')
            ->icon('bs.check-circle')
            ->method('save')
            ->type(Color::PRIMARY);

        if ($this->company?->exists) {
            $bar[] = Button::make('Удалить')
                ->icon('bs.trash')
                ->method('remove')
                ->type(Color::DANGER)
                ->confirm('Удалить клиента и все контакты?');
        }

        return $bar;
    }

    public function layout(): iterable
    {
        return [
            Layout::block(ClientCompanyEditLayout::class)
                ->title('Данные компании')
                ->description('Контакты привязываются к компании; проекты можно связать без контактов.'),

            Layout::table('contacts', [
                TD::make('full_name', 'ФИО')
                    ->render(fn (ClientContact $contact) => Link::make($contact->fullName())
                        ->route('platform.client-contacts.edit', $contact)),
                TD::make('position', 'Должность'),
                TD::make('emails', 'Email')
                    ->render(fn (ClientContact $contact) => e(implode(', ', $contact->emails ?? []))),
                TD::make('open', '')
                    ->render(fn (ClientContact $contact) => Link::make('Открыть')
                        ->route('platform.client-contacts.edit', $contact)),
            ])->title('Контакты'),
        ];
    }

    public function save(Request $request, ClientCompany $company, ClientCompanyService $companies)
    {
        $validated = $request->validate([
            'company.name' => ['required', 'string', 'max:255'],
            'company.description' => ['nullable', 'string'],
            'company.notes' => ['nullable', 'string'],
            'company.project_ids' => ['nullable', 'array'],
            'company.project_ids.*' => ['integer', 'exists:projects,id'],
        ]);

        $payload = $validated['company'];
        $payload['project_ids'] = $payload['project_ids'] ?? [];

        if ($company->exists) {
            $companies->update($company, $payload);
            Toast::info('Клиент обновлён.');

            return;
        }

        $created = $companies->create($payload);
        Toast::info('Клиент создан.');

        return redirect()->route('platform.client-companies.edit', $created);
    }

    public function remove(ClientCompany $company, ClientCompanyService $companies)
    {
        if (! $company->exists) {
            return redirect()->route('platform.client-companies');
        }

        $companies->delete($company);
        Toast::info('Клиент удалён.');

        return redirect()->route('platform.client-companies');
    }
}
