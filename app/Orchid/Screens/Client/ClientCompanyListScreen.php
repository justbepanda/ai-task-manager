<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Client;

use App\Models\ClientCompany;
use App\Orchid\Layouts\Client\ClientCompanyListLayout;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Screen;

class ClientCompanyListScreen extends Screen
{
    public function query(): iterable
    {
        return [
            'companies' => ClientCompany::query()
                ->withCount(['contacts', 'projects'])
                ->latest('id')
                ->paginate(),
        ];
    }

    public function name(): ?string
    {
        return 'Клиенты';
    }

    public function description(): ?string
    {
        return 'Компании-клиенты и их контакты.';
    }

    public function commandBar(): iterable
    {
        return [
            Link::make('Создать')
                ->icon('bs.plus-circle')
                ->route('platform.client-companies.create'),
        ];
    }

    public function layout(): iterable
    {
        return [
            ClientCompanyListLayout::class,
        ];
    }
}
