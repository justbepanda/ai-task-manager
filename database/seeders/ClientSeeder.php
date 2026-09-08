<?php

namespace Database\Seeders;

use App\Domain\Clients\ClientCompanyService;
use App\Domain\Clients\ClientContactService;
use App\Models\ClientCompany;
use App\Models\Project;
use Illuminate\Database\Seeder;

class ClientSeeder extends Seeder
{
    public function run(): void
    {
        $companies = app(ClientCompanyService::class);
        $contacts = app(ClientContactService::class);

        $shop = Project::query()->where('name', 'Интернет-магазин А')->first();
        $website = Project::query()->where('name', 'Корпоративный сайт')->first();

        $romashka = ClientCompany::query()->where('name', 'ООО Ромашка')->first();
        if ($romashka === null) {
            $romashka = $companies->create([
                'name' => 'ООО Ромашка',
                'description' => 'Клиент e-commerce проекта «Интернет-магазин А».',
                'notes' => 'Основной контакт по оплате и доставке.',
                'project_ids' => array_filter([$shop?->id]),
            ]);
        } else {
            $companies->update($romashka, [
                'description' => 'Клиент e-commerce проекта «Интернет-магазин А».',
                'notes' => 'Основной контакт по оплате и доставке.',
                'project_ids' => array_filter([$shop?->id]),
            ]);
        }

        $sever = ClientCompany::query()->where('name', 'АО Север')->first();
        if ($sever === null) {
            $sever = $companies->create([
                'name' => 'АО Север',
                'description' => 'Клиент корпоративного сайта.',
                'notes' => null,
                'project_ids' => array_filter([$website?->id]),
            ]);
        } else {
            $companies->update($sever, [
                'description' => 'Клиент корпоративного сайта.',
                'project_ids' => array_filter([$website?->id]),
            ]);
        }

        $this->seedContact($contacts, $romashka, 'Иванова', 'Анна', 'Сергеевна', 'Руководитель проекта', [
            'client@romashka-shop.ru',
            'pm@romashka-shop.ru',
        ], array_filter([$shop?->id]));

        $this->seedContact($contacts, $romashka, 'Петров', 'Игорь', 'Викторович', 'Бухгалтер', [
            'finance@romashka-shop.ru',
        ], array_filter([$shop?->id]));

        $this->seedContact($contacts, $sever, 'Сидорова', 'Мария', 'Андреевна', 'Digital-директор', [
            'dig@sever.example',
        ], array_filter([$website?->id]));

        $this->seedContact($contacts, $sever, 'Кузнецов', 'Павел', null, 'Контент-менеджер', [
            'content@sever.example',
        ], array_filter([$website?->id]));
    }

    /**
     * @param  list<string>  $emails
     * @param  list<int>  $projectIds
     */
    private function seedContact(
        ClientContactService $contacts,
        ClientCompany $company,
        string $lastName,
        string $firstName,
        ?string $middleName,
        string $position,
        array $emails,
        array $projectIds,
    ): void {
        $existing = $company->contacts()
            ->where('last_name', $lastName)
            ->where('first_name', $firstName)
            ->first();

        $payload = [
            'last_name' => $lastName,
            'first_name' => $firstName,
            'middle_name' => $middleName,
            'position' => $position,
            'emails' => $emails,
            'telegram' => null,
            'phone' => null,
            'notes' => null,
            'project_ids' => $projectIds,
        ];

        if ($existing !== null) {
            $contacts->update($existing, $payload);
        } else {
            $contacts->create($company, $payload);
        }
    }
}
