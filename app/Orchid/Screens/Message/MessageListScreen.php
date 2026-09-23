<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Message;

use App\Enums\MessageClassification;
use App\Jobs\ApplyMailFiltersJob;
use App\Jobs\SyncYandexMailJob;
use App\Models\Message;
use App\Orchid\Layouts\Message\MessageFiltersLayout;
use App\Orchid\Layouts\Message\MessageListLayout;
use Illuminate\Http\Request;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Screen;
use Orchid\Support\Color;
use Orchid\Support\Facades\Toast;

class MessageListScreen extends Screen
{
    public function query(Request $request): iterable
    {
        $filter = $request->input('filter', []);
        if (! is_array($filter)) {
            $filter = [];
        }

        if ($this->isIgnoredList()) {
            $filter['classification'] = MessageClassification::Ignored->value;
        }

        $query = Message::query()
            ->with(['project', 'projectTask', 'events'])
            ->latest('received_at');

        if (! empty($filter['classification'])) {
            $query->where('classification', $filter['classification']);
        }

        if (! empty($filter['processing_status'])) {
            $query->where('processing_status', $filter['processing_status']);
        }

        if (! empty($filter['project_id'])) {
            if ($filter['project_id'] === 'none') {
                $query->whereNull('project_id');
            } else {
                $query->where('project_id', (int) $filter['project_id']);
            }
        }

        if (! empty($filter['project_task_id'])) {
            $query->where('project_task_id', (int) $filter['project_task_id']);
        }

        if (($filter['requires_manager'] ?? null) === '1' || ($filter['requires_manager'] ?? null) === 1) {
            $query->where('requires_manager', true);
        }

        return [
            'filter' => $filter,
            'messages' => $query->paginate(30)->appends(['filter' => $filter]),
        ];
    }

    public function name(): ?string
    {
        return $this->isIgnoredList() ? 'Игнорируемые' : 'Входящие';
    }

    public function description(): ?string
    {
        return $this->isIgnoredList()
            ? 'Письма из глобального чёрного списка (системные, Яндекс ID и т.п.).'
            : 'Нормализованные сообщения и результаты анализа.';
    }

    public function commandBar(): iterable
    {
        if ($this->isIgnoredList()) {
            return [
                Button::make('Пересобрать игнорируемые')
                    ->icon('bs.arrow-clockwise')
                    ->method('rebuildIgnored')
                    ->confirm('Применить текущие фильтры ко всем письмам? Совпадения станут игнорируемыми, лишние выйдут из списка.')
                    ->type(Color::PRIMARY),
                Link::make('Фильтры почты')
                    ->icon('bs.funnel')
                    ->route('platform.mail-filters'),
            ];
        }

        return [
            Button::make('Синхронизировать почту')
                ->icon('bs.arrow-repeat')
                ->method('syncMail')
                ->type(Color::PRIMARY),
            Link::make('Распределить по проектам')
                ->icon('bs.diagram-3')
                ->route('platform.messages.assign'),
            Link::make('Распределить (LLM)')
                ->icon('bs.stars')
                ->route('platform.messages.assign-llm'),
        ];
    }

    public function layout(): iterable
    {
        return [
            MessageFiltersLayout::class,
            MessageListLayout::class,
        ];
    }

    public function syncMail(): void
    {
        SyncYandexMailJob::dispatch(userId: auth()->id());

        Toast::info('Синхронизация в очереди. Нужен запущенный worker (docker compose up -d queue). Если писем нет — «Сервисные команды» → «Синхронизировать почту сейчас (без очереди)».');
    }

    public function rebuildIgnored(): void
    {
        ApplyMailFiltersJob::dispatch(userId: auth()->id());

        Toast::info('Пересборка игнорируемых поставлена в очередь. Смотрите «Журнал» / обновите список после worker’а.');
    }

    public function applyFilters(Request $request)
    {
        $ignoredOnly = $this->isIgnoredList();
        $filter = $request->input('filter', []);
        if (! is_array($filter)) {
            $filter = [];
        }

        if ($ignoredOnly) {
            $filter['classification'] = MessageClassification::Ignored->value;
        }

        return redirect()->route(
            $ignoredOnly ? 'platform.messages.ignored' : 'platform.messages',
            ['filter' => $filter],
        );
    }

    private function isIgnoredList(): bool
    {
        return request()->routeIs('platform.messages.ignored');
    }
}
