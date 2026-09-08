<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Client;

use App\Models\ClientCompany;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Layouts\Table;
use Orchid\Screen\TD;

class ClientCompanyListLayout extends Table
{
    protected $target = 'companies';

    protected function columns(): iterable
    {
        return [
            TD::make('id', 'ID')->sort()->width('80'),
            TD::make('name', 'Название')
                ->sort()
                ->render(fn (ClientCompany $company) => Link::make($company->name)
                    ->route('platform.client-companies.edit', $company)),
            TD::make('contacts_count', 'Контакты')
                ->render(fn (ClientCompany $company) => (string) ($company->contacts_count ?? $company->contacts()->count())),
            TD::make('projects_count', 'Проекты')
                ->render(fn (ClientCompany $company) => (string) ($company->projects_count ?? $company->projects()->count())),
            TD::make('updated_at', 'Обновлён')
                ->render(fn (ClientCompany $company) => $company->updated_at?->format('d.m.Y H:i')),
        ];
    }
}
