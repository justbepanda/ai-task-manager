<?php

declare(strict_types=1);

namespace App\Orchid\Screens\User;

use App\Orchid\Layouts\User\ProfilePasswordLayout;
use App\Orchid\Layouts\User\UserEditLayout;
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

class UserProfileScreen extends Screen
{
    /**
     * Fetch data to be displayed on the screen.
     *
     *
     * @return array
     */
    public function query(Request $request): iterable
    {
        return [
            'user' => $request->user(),
        ];
    }

    /**
     * The name of the screen displayed in the header.
     */
    public function name(): ?string
    {
        return 'Мой аккаунт';
    }

    /**
     * Display header description.
     */
    public function description(): ?string
    {
        return 'Обновите данные аккаунта: имя, email и пароль.';
    }

    /**
     * The screen's action buttons.
     *
     * @return Action[]
     */
    public function commandBar(): iterable
    {
        return [
            Button::make('Вернуться в свой аккаунт')
                ->novalidate()
                ->canSee(Impersonation::isSwitch())
                ->icon('bs.people')
                ->route('platform.switch.logout'),

            Button::make('Выйти')
                ->novalidate()
                ->icon('bs.box-arrow-left')
                ->route('platform.logout'),
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
                ->description('Обновите имя и адрес электронной почты.')
                ->commands(
                    Button::make('Сохранить')
                        ->type(Color::BASIC())
                        ->icon('bs.check-circle')
                        ->method('save')
                ),

            Layout::block(ProfilePasswordLayout::class)
                ->title('Смена пароля')
                ->description('Используйте длинный случайный пароль для защиты учётной записи.')
                ->commands(
                    Button::make('Обновить пароль')
                        ->type(Color::BASIC())
                        ->icon('bs.check-circle')
                        ->method('changePassword')
                ),
        ];
    }

    public function save(Request $request): void
    {
        $request->validate([
            'user.name'  => 'required|string',
            'user.email' => [
                'required',
                Rule::unique(User::class, 'email')->ignore($request->user()),
            ],
        ]);

        $request->user()
            ->fill($request->get('user'))
            ->save();

        Toast::info('Профиль обновлён.');
    }

    public function changePassword(Request $request): void
    {
        $guard = config('platform.guard', 'web');
        $request->validate([
            'old_password' => 'required|current_password:'.$guard,
            'password'     => 'required|confirmed|different:old_password',
        ]);

        tap($request->user(), function ($user) use ($request) {
            $user->password = Hash::make($request->get('password'));
        })->save();

        Toast::info('Пароль изменён.');
    }
}
