<?php

declare(strict_types=1);

namespace App\Orchid\Screens\User;

use App\Orchid\Layouts\Role\RolePermissionLayout;
use App\Orchid\Layouts\User\UserEditLayout;
use App\Orchid\Layouts\User\UserPasswordLayout;
use App\Orchid\Layouts\User\UserRoleLayout;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Orchid\Access\Impersonation;
use App\Models\User;
use Orchid\Screen\Action;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Screen;
use Orchid\Support\Color;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;

class UserEditScreen extends Screen
{
    /**
     * @var User
     */
    public $user;

    /**
     * Fetch data to be displayed on the screen.
     *
     * @return array
     */
    public function query(User $user): iterable
    {
        $user->load(['roles']);

        return [
            'user'       => $user,
            'permission' => $user->statusOfPermissions(),
        ];
    }

    /**
     * The name of the screen displayed in the header.
     */
    public function name(): ?string
    {
        return $this->user->exists ? 'Редактирование пользователя' : 'Создание пользователя';
    }

    /**
     * Display header description.
     */
    public function description(): ?string
    {
        return 'Профиль пользователя и права доступа, включая назначенные роли.';
    }

    public function permission(): ?iterable
    {
        return [
            'platform.systems.users',
        ];
    }

    /**
     * The screen's action buttons.
     *
     * @return Action[]
     */
    public function commandBar(): iterable
    {
        return [
            Button::make('Войти от имени пользователя')
                ->icon('bg.box-arrow-in-right')
                ->confirm('Вы можете вернуться в свой аккаунт, выйдя из системы.')
                ->method('loginAs')
                ->canSee($this->user->exists && $this->user->id !== \request()->user()->id),

            Button::make('Удалить')
                ->icon('bs.trash3')
                ->confirm('После удаления учётной записи все связанные ресурсы и данные будут безвозвратно удалены. Перед удалением сохраните нужные данные.')
                ->method('remove')
                ->canSee($this->user->exists),

            Button::make('Сохранить')
                ->icon('bs.check-circle')
                ->method('save'),
        ];
    }

    /**
     * @return \Orchid\Screen\Layout[]
     */
    public function layout(): iterable
    {
        return [

            Layout::block(UserEditLayout::class)
                ->title('Данные профиля')
                ->description('Обновите имя и адрес электронной почты пользователя.')
                ->commands(
                    Button::make('Сохранить')
                        ->type(Color::BASIC)
                        ->icon('bs.check-circle')
                        ->canSee($this->user->exists)
                        ->method('save')
                ),

            Layout::block(UserPasswordLayout::class)
                ->title('Пароль')
                ->description('Используйте длинный случайный пароль для защиты учётной записи.')
                ->commands(
                    Button::make('Сохранить')
                        ->type(Color::BASIC)
                        ->icon('bs.check-circle')
                        ->canSee($this->user->exists)
                        ->method('save')
                ),

            Layout::block(UserRoleLayout::class)
                ->title('Роли')
                ->description('Роль определяет набор задач, которые разрешено выполнять пользователю.')
                ->commands(
                    Button::make('Сохранить')
                        ->type(Color::BASIC)
                        ->icon('bs.check-circle')
                        ->canSee($this->user->exists)
                        ->method('save')
                ),

            Layout::block(RolePermissionLayout::class)
                ->title('Права доступа')
                ->description('Разрешите пользователю действия, не предусмотренные его ролями.')
                ->commands(
                    Button::make('Сохранить')
                        ->type(Color::BASIC)
                        ->icon('bs.check-circle')
                        ->canSee($this->user->exists)
                        ->method('save')
                ),

        ];
    }

    /**
     * @return RedirectResponse
     */
    public function save(User $user, Request $request)
    {
        $request->validate([
            'user.email' => [
                'required',
                Rule::unique(User::class, 'email')->ignore($user),
            ],
        ]);

        $permissions = collect($request->get('permissions'))
            ->map(fn ($value, $key) => [base64_decode($key) => $value])
            ->collapse()
            ->toArray();

        $user->when($request->filled('user.password'), function (Builder $builder) use ($request) {
            $builder->getModel()->password = Hash::make($request->input('user.password'));
        });

        $user
            ->fill($request->collect('user')->except(['password', 'permissions', 'roles'])->toArray())
            ->forceFill(['permissions' => $permissions])
            ->save();

        $user->replaceRoles($request->input('user.roles'));

        Toast::info('Пользователь сохранён.');

        return redirect()->route('platform.systems.users');
    }

    /**
     * @throws \Exception
     *
     * @return RedirectResponse
     */
    public function remove(User $user)
    {
        $user->delete();

        Toast::info('Пользователь удалён.');

        return redirect()->route('platform.systems.users');
    }

    /**
     * @return RedirectResponse
     */
    public function loginAs(User $user)
    {
        Impersonation::loginAs($user);

        Toast::info('Вы вошли от имени этого пользователя.');

        return redirect()->route(config('platform.index'));
    }
}
