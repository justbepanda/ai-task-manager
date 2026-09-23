<?php

declare(strict_types=1);

namespace App\Orchid;

use Orchid\Platform\Dashboard;
use Orchid\Platform\ItemPermission;
use Orchid\Platform\OrchidServiceProvider;
use Orchid\Screen\Actions\Menu;

class PlatformProvider extends OrchidServiceProvider
{
    public function boot(Dashboard $dashboard): void
    {
        parent::boot($dashboard);
    }

    /**
     * @return Menu[]
     */
    public function menu(): array
    {
        return [
            Menu::make('Обзор')
                ->icon('bs.speedometer2')
                ->title('Менеджер проектов')
                ->route(config('platform.index')),

            Menu::make('Входящие')
                ->icon('bs.inbox')
                ->route('platform.messages')
                ->title('Почта'),

            Menu::make('Игнорируемые')
                ->icon('bs.eye-slash')
                ->route('platform.messages.ignored'),

            Menu::make('Распределение')
                ->icon('bs.diagram-3')
                ->route('platform.messages.assign'),

            Menu::make('Распределение (LLM)')
                ->icon('bs.stars')
                ->route('platform.messages.assign-llm'),

            Menu::make('Фильтры почты')
                ->icon('bs.funnel')
                ->route('platform.mail-filters'),

            Menu::make('Проекты')
                ->icon('bs.folder2')
                ->route('platform.projects'),

            Menu::make('Задачи')
                ->icon('bs.list-task')
                ->route('platform.tasks'),

            Menu::make('Клиенты')
                ->icon('bs.building')
                ->route('platform.client-companies')
                ->title('Справочники'),

            Menu::make('Исполнители')
                ->icon('bs.person-badge')
                ->route('platform.executors'),

            Menu::make('Действия менеджера')
                ->icon('bs.clipboard-check')
                ->route('platform.manager-actions')
                ->title('Работа'),

            Menu::make('Метрики гипотезы')
                ->icon('bs.bar-chart')
                ->route('platform.metrics.hypothesis'),

            Menu::make('Журнал')
                ->icon('bs.journal-text')
                ->route('platform.activity-logs'),

            Menu::make('Сервисные команды')
                ->icon('bs.terminal')
                ->route('platform.admin-tools'),

            Menu::make('Настройки')
                ->icon('bs.gear')
                ->route('platform.settings')
                ->title('Система'),

            Menu::make('Пользователи')
                ->icon('bs.people')
                ->route('platform.systems.users')
                ->permission('platform.systems.users')
                ->title('Управление доступом'),

            Menu::make('Роли')
                ->icon('bs.shield')
                ->route('platform.systems.roles')
                ->permission('platform.systems.roles'),
        ];
    }

    /**
     * @return ItemPermission[]
     */
    public function permissions(): array
    {
        return [
            ItemPermission::group('Система')
                ->addPermission('platform.systems.roles', 'Роли')
                ->addPermission('platform.systems.users', 'Пользователи'),
        ];
    }
}
