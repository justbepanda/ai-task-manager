<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Settings;

use App\Domain\Settings\AppSettingService;
use App\Models\AppSetting;
use Illuminate\Http\Request;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Fields\CheckBox;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Screen;
use Orchid\Support\Color;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;

class AppSettingEditScreen extends Screen
{
    public ?AppSetting $settings = null;

    public function query(): iterable
    {
        return [
            'settings' => AppSetting::current(),
        ];
    }

    public function name(): ?string
    {
        return 'Настройки';
    }

    public function description(): ?string
    {
        return 'Глобальные параметры обработки входящей почты.';
    }

    public function commandBar(): iterable
    {
        return [
            Button::make('Сохранить')
                ->icon('bs.check-circle')
                ->method('save')
                ->type(Color::PRIMARY),
            Link::make('Фильтры почты')
                ->icon('bs.funnel')
                ->route('platform.mail-filters'),
        ];
    }

    public function layout(): iterable
    {
        return [
            Layout::rows([
                CheckBox::make('settings.auto_create_projects')
                    ->title('Автоматически создавать проекты (Redmine)')
                    ->placeholder('Создавать проект из темы Redmine, если совпадения ещё нет')
                    ->sendTrueOrFalse()
                    ->help('Только для писем от настроенных отправителей Redmine. Без LLM. Если включено вместе с LLM — срабатывает первым.'),

                Input::make('settings.auto_create_confidence_threshold')
                    ->type('number')
                    ->step('0.01')
                    ->min(0)
                    ->max(1)
                    ->title('Минимальная уверенность (Redmine)')
                    ->help('Создание/привязка, если оценка правила Redmine (обычно 0.97) ≥ этого порога.'),
            ])->title('Проекты из Redmine'),

            Layout::rows([
                CheckBox::make('settings.auto_assign_projects_llm')
                    ->title('Автоматически распределять через LLM')
                    ->placeholder('Привязывать или создавать проекты по всем нераспределённым письмам')
                    ->sendTrueOrFalse()
                    ->help('Для любых писем (не только Redmine), если после правил/Redmine проект ещё не найден. LLM вызывается только при достаточной уверенности.'),

                Input::make('settings.auto_assign_llm_confidence_threshold')
                    ->type('number')
                    ->step('0.01')
                    ->min(0)
                    ->max(1)
                    ->title('Минимальная уверенность (LLM)')
                    ->help('Применять ответ LLM, только если confidence ≥ порога. Иначе письмо остаётся без проекта.'),
            ])->title('Распределение через LLM'),
        ];
    }

    public function save(Request $request, AppSettingService $service): void
    {
        $validated = $request->validate([
            'settings.auto_create_projects' => ['sometimes', 'boolean'],
            'settings.auto_create_confidence_threshold' => ['required', 'numeric', 'min:0', 'max:1'],
            'settings.auto_assign_projects_llm' => ['sometimes', 'boolean'],
            'settings.auto_assign_llm_confidence_threshold' => ['required', 'numeric', 'min:0', 'max:1'],
        ]);

        $data = $validated['settings'] ?? [];

        $service->save([
            'auto_create_projects' => (bool) ($data['auto_create_projects'] ?? false),
            'auto_create_confidence_threshold' => (float) $data['auto_create_confidence_threshold'],
            'auto_assign_projects_llm' => (bool) ($data['auto_assign_projects_llm'] ?? false),
            'auto_assign_llm_confidence_threshold' => (float) $data['auto_assign_llm_confidence_threshold'],
        ]);

        Toast::info('Настройки сохранены.');
    }
}
