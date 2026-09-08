<?php

declare(strict_types=1);

use App\Orchid\Screens\Client\ClientCompanyEditScreen;
use App\Orchid\Screens\Client\ClientCompanyListScreen;
use App\Orchid\Screens\Client\ClientContactEditScreen;
use App\Orchid\Screens\DashboardScreen;
use App\Orchid\Screens\Executor\ExecutorEditScreen;
use App\Orchid\Screens\Executor\ExecutorListScreen;
use App\Orchid\Screens\MailFilter\MailFilterSettingEditScreen;
use App\Orchid\Screens\ManagerAction\ManagerActionListScreen;
use App\Orchid\Screens\ManagerAction\ManagerActionViewScreen;
use App\Orchid\Screens\Message\MessageListScreen;
use App\Orchid\Screens\Message\MessageProjectAssignScreen;
use App\Orchid\Screens\Message\MessageViewScreen;
use App\Orchid\Screens\Metrics\HypothesisMetricsScreen;
use App\Orchid\Screens\Project\ProjectEditScreen;
use App\Orchid\Screens\Project\ProjectListScreen;
use App\Orchid\Screens\Role\RoleEditScreen;
use App\Orchid\Screens\Role\RoleListScreen;
use App\Orchid\Screens\User\UserEditScreen;
use App\Orchid\Screens\User\UserListScreen;
use App\Orchid\Screens\User\UserProfileScreen;
use Illuminate\Support\Facades\Route;
use Tabuna\Breadcrumbs\Trail;

// Main dashboard
Route::screen('/main', DashboardScreen::class)
    ->name('platform.main');

Route::screen('metrics/hypothesis', HypothesisMetricsScreen::class)
    ->name('platform.metrics.hypothesis')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.index')
        ->push('Метрики гипотезы', route('platform.metrics.hypothesis')));

Route::screen('profile', UserProfileScreen::class)
    ->name('platform.profile')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.index')
        ->push('Профиль', route('platform.profile')));

Route::screen('users/{user}/edit', UserEditScreen::class)
    ->name('platform.systems.users.edit')
    ->breadcrumbs(fn (Trail $trail, $user) => $trail
        ->parent('platform.systems.users')
        ->push($user->name, route('platform.systems.users.edit', $user)));

Route::screen('users/create', UserEditScreen::class)
    ->name('platform.systems.users.create')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.systems.users')
        ->push('Создать', route('platform.systems.users.create')));

Route::screen('users', UserListScreen::class)
    ->name('platform.systems.users')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.index')
        ->push('Пользователи', route('platform.systems.users')));

Route::screen('roles/{role}/edit', RoleEditScreen::class)
    ->name('platform.systems.roles.edit')
    ->breadcrumbs(fn (Trail $trail, $role) => $trail
        ->parent('platform.systems.roles')
        ->push($role->name, route('platform.systems.roles.edit', $role)));

Route::screen('roles/create', RoleEditScreen::class)
    ->name('platform.systems.roles.create')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.systems.roles')
        ->push('Создать', route('platform.systems.roles.create')));

Route::screen('roles', RoleListScreen::class)
    ->name('platform.systems.roles')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.index')
        ->push('Роли', route('platform.systems.roles')));

// Projects
Route::screen('projects/create', ProjectEditScreen::class)
    ->name('platform.projects.create')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.projects')
        ->push('Создать', route('platform.projects.create')));

Route::screen('projects/{project}/edit', ProjectEditScreen::class)
    ->name('platform.projects.edit')
    ->breadcrumbs(fn (Trail $trail, $project) => $trail
        ->parent('platform.projects')
        ->push($project->name ?? 'Проект', route('platform.projects.edit', $project)));

Route::screen('projects', ProjectListScreen::class)
    ->name('platform.projects')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.index')
        ->push('Проекты', route('platform.projects')));

// Clients (Клиенты) / Contacts (Контакты)
Route::screen('client-companies/create', ClientCompanyEditScreen::class)
    ->name('platform.client-companies.create')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.client-companies')
        ->push('Создать', route('platform.client-companies.create')));

Route::screen('client-companies/{company}/edit', ClientCompanyEditScreen::class)
    ->name('platform.client-companies.edit')
    ->breadcrumbs(fn (Trail $trail, $company) => $trail
        ->parent('platform.client-companies')
        ->push($company->name ?? 'Клиент', route('platform.client-companies.edit', $company)));

Route::screen('client-companies', ClientCompanyListScreen::class)
    ->name('platform.client-companies')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.index')
        ->push('Клиенты', route('platform.client-companies')));

Route::screen('client-companies/{company}/contacts/create', ClientContactEditScreen::class)
    ->name('platform.client-contacts.create')
    ->breadcrumbs(fn (Trail $trail, $company) => $trail
        ->parent('platform.client-companies')
        ->push($company->name ?? 'Клиент', route('platform.client-companies.edit', $company))
        ->push('Новый контакт', route('platform.client-contacts.create', $company)));

Route::screen('client-contacts/{contact}/edit', ClientContactEditScreen::class)
    ->name('platform.client-contacts.edit')
    ->breadcrumbs(fn (Trail $trail, $contact) => $trail
        ->parent('platform.client-companies')
        ->push($contact->clientCompany?->name ?? 'Клиент', route('platform.client-companies.edit', $contact->clientCompany))
        ->push($contact->fullName() ?: 'Контакт', route('platform.client-contacts.edit', $contact)));

// Executors (Исполнители)
Route::screen('executors/create', ExecutorEditScreen::class)
    ->name('platform.executors.create')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.executors')
        ->push('Создать', route('platform.executors.create')));

Route::screen('executors/{executor}/edit', ExecutorEditScreen::class)
    ->name('platform.executors.edit')
    ->breadcrumbs(fn (Trail $trail, $executor) => $trail
        ->parent('platform.executors')
        ->push($executor->fullName() ?: 'Исполнитель', route('platform.executors.edit', $executor)));

Route::screen('executors', ExecutorListScreen::class)
    ->name('platform.executors')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.index')
        ->push('Исполнители', route('platform.executors')));

// Inbox / Messages
Route::screen('messages/ignored', MessageListScreen::class)
    ->name('platform.messages.ignored')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.index')
        ->push('Игнорируемые', route('platform.messages.ignored')));

Route::screen('messages/assign', MessageProjectAssignScreen::class)
    ->name('platform.messages.assign')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.messages')
        ->push('Распределение', route('platform.messages.assign')));

Route::screen('mail-filters', MailFilterSettingEditScreen::class)
    ->name('platform.mail-filters')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.index')
        ->push('Фильтры почты', route('platform.mail-filters')));

Route::screen('messages/{message}', MessageViewScreen::class)
    ->name('platform.messages.view')
    ->whereNumber('message')
    ->breadcrumbs(fn (Trail $trail, $message) => $trail
        ->parent('platform.messages')
        ->push('Письмо #'.$message->id, route('platform.messages.view', $message)));

Route::screen('messages', MessageListScreen::class)
    ->name('platform.messages')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.index')
        ->push('Входящие', route('platform.messages')));

// Manager actions
Route::screen('manager-actions/{manager_action}', ManagerActionViewScreen::class)
    ->name('platform.manager-actions.view')
    ->whereNumber('manager_action')
    ->breadcrumbs(fn (Trail $trail, $manager_action) => $trail
        ->parent('platform.manager-actions')
        ->push('Действие #'.$manager_action->id, route('platform.manager-actions.view', $manager_action)));

Route::screen('manager-actions', ManagerActionListScreen::class)
    ->name('platform.manager-actions')
    ->breadcrumbs(fn (Trail $trail) => $trail
        ->parent('platform.index')
        ->push('Действия менеджера', route('platform.manager-actions')));
