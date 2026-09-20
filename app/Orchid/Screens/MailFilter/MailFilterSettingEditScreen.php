<?php

declare(strict_types=1);

namespace App\Orchid\Screens\MailFilter;

use App\Domain\Mail\MailFilterSettingService;
use App\Jobs\ApplyMailFiltersJob;
use App\Models\MailFilterSetting;
use Illuminate\Http\Request;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Fields\TextArea;
use Orchid\Screen\Screen;
use Orchid\Support\Color;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;

class MailFilterSettingEditScreen extends Screen
{
    public ?MailFilterSetting $settings = null;

    public function query(MailFilterSettingService $service): iterable
    {
        $settings = MailFilterSetting::current();

        $settings->setAttribute('blocked_emails_text', $service->listToLines($settings->blocked_emails));
        $settings->setAttribute('blocked_domains_text', $service->listToLines($settings->blocked_domains));
        $settings->setAttribute('subject_stop_words_text', $service->listToLines($settings->subject_stop_words));
        $settings->setAttribute('body_stop_words_text', $service->listToLines($settings->body_stop_words));

        return [
            'settings' => $settings,
        ];
    }

    public function name(): ?string
    {
        return 'Фильтры почты';
    }

    public function description(): ?string
    {
        return 'Глобальный чёрный список: письма попадают в «Игнорируемое» до технических/клиентских правил.';
    }

    public function commandBar(): iterable
    {
        return [
            Button::make('Сохранить')
                ->icon('bs.check-circle')
                ->method('save')
                ->type(Color::PRIMARY),
            Button::make('Пересобрать игнорируемые')
                ->icon('bs.arrow-clockwise')
                ->method('rebuildIgnored')
                ->confirm('Применить текущие (уже сохранённые) фильтры ко всем письмам? Несохранённые правки в форме не учитываются — сначала «Сохранить».')
                ->type(Color::WARNING),
            Link::make('Игнорируемые')
                ->icon('bs.eye-slash')
                ->route('platform.messages.ignored'),
        ];
    }

    public function layout(): iterable
    {
        return [
            Layout::rows([
                TextArea::make('settings.blocked_emails_text')
                    ->title('Заблокированные адреса')
                    ->help('Один email или фрагмент на строку (например noreply@). Точное совпадение или вхождение.')
                    ->rows(8),

                TextArea::make('settings.blocked_domains_text')
                    ->title('Заблокированные домены')
                    ->help('Один домен на строку (например id.yandex.ru).')
                    ->rows(6),

                TextArea::make('settings.subject_stop_words_text')
                    ->title('Стоп-слова в теме')
                    ->help('Подстрока в теме (без учёта регистра) или regex:… например regex:/\[Поддержка\s*-[^\]]*\].*Оценка:\s+\S+/u')
                    ->rows(8),

                TextArea::make('settings.body_stop_words_text')
                    ->title('Стоп-слова в тексте')
                    ->help('Подстрока в начале тела или regex:/…/u')
                    ->rows(8),
            ])->title('Чёрный список'),
        ];
    }

    public function save(Request $request, MailFilterSettingService $service): void
    {
        $validated = $request->validate([
            'settings.blocked_emails_text' => ['nullable', 'string'],
            'settings.blocked_domains_text' => ['nullable', 'string'],
            'settings.subject_stop_words_text' => ['nullable', 'string'],
            'settings.body_stop_words_text' => ['nullable', 'string'],
        ]);

        $data = $validated['settings'] ?? [];

        $service->saveFromLines([
            'blocked_emails' => $data['blocked_emails_text'] ?? null,
            'blocked_domains' => $data['blocked_domains_text'] ?? null,
            'subject_stop_words' => $data['subject_stop_words_text'] ?? null,
            'body_stop_words' => $data['body_stop_words_text'] ?? null,
        ]);

        Toast::info('Фильтры почты сохранены.');
    }

    public function rebuildIgnored(): void
    {
        ApplyMailFiltersJob::dispatch(userId: auth()->id());

        Toast::info('Пересборка игнорируемых поставлена в очередь. Смотрите «Журнал» и список «Игнорируемые» после worker’а.');
    }
}