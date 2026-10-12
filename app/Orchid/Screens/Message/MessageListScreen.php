<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Message;

use App\Enums\MessageClassification;
use App\Enums\MessageProcessingStatus;
use App\Jobs\ApplyMailFiltersJob;
use App\Jobs\SyncYandexMailJob;
use App\Models\Message;
use App\Orchid\Layouts\Dashboard\ClickableMetricsLayout;
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

        $this->applyFiltersToQuery($query, $filter);

        $data = [
            'filter' => $filter,
            'messages' => $query->paginate(30)->appends(['filter' => $filter]),
        ];

        if (! $this->isIgnoredList()) {
            $data['inboxMetrics'] = $this->inboxMetrics($filter);
            $data['inboxMetricsExtra'] = $this->inboxMetricsExtra($filter);
        }

        return $data;
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
        if ($this->isIgnoredList()) {
            return [
                MessageFiltersLayout::class,
                MessageListLayout::class,
            ];
        }

        return [
            new ClickableMetricsLayout([
                'Всего писем' => 'inboxMetrics.total',
                'Ожидают обработки' => 'inboxMetrics.pending',
                'Требуют ручной обработки' => 'inboxMetrics.needs_manual',
                'Неизвестные' => 'inboxMetrics.unknown',
                'Игнорируемые' => 'inboxMetrics.ignored',
                'Требуют внимания' => 'inboxMetrics.requires_manager',
            ]),
            new ClickableMetricsLayout([
                'Клиентские' => 'inboxMetricsExtra.client',
                'Заявки с сайта' => 'inboxMetricsExtra.site_lead',
                'Технические' => 'inboxMetricsExtra.technical',
                'Без проекта' => 'inboxMetricsExtra.no_project',
                'Ошибка обработки' => 'inboxMetricsExtra.failed',
            ]),
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

        $filter = array_filter(
            $filter,
            static fn (mixed $value): bool => $value !== null && $value !== '',
        );

        return redirect()->route(
            $ignoredOnly ? 'platform.messages.ignored' : 'platform.messages',
            $filter === [] ? [] : ['filter' => $filter],
        );
    }

    private function isIgnoredList(): bool
    {
        return request()->routeIs('platform.messages.ignored');
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\Message>  $query
     * @param  array<string, mixed>  $filter
     */
    private function applyFiltersToQuery($query, array $filter): void
    {
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

        $requiresManager = $filter['requires_manager'] ?? null;
        if ($requiresManager === '1' || $requiresManager === 1) {
            $query->where('requires_manager', true);
        } elseif ($requiresManager === '0' || $requiresManager === 0) {
            $query->where('requires_manager', false);
        }
    }

    /**
     * @param  array<string, mixed>  $currentFilter
     * @return array<string, array{value: int, url: string, active?: bool}>
     */
    private function inboxMetrics(array $currentFilter): array
    {
        return [
            'total' => $this->metric(
                Message::query()->count(),
                [],
                $currentFilter,
                [],
            ),
            'pending' => $this->metric(
                Message::query()->where('processing_status', MessageProcessingStatus::Pending)->count(),
                ['processing_status' => MessageProcessingStatus::Pending->value],
                $currentFilter,
                ['processing_status'],
            ),
            'needs_manual' => $this->metric(
                Message::query()->where('processing_status', MessageProcessingStatus::NeedsManual)->count(),
                ['processing_status' => MessageProcessingStatus::NeedsManual->value],
                $currentFilter,
                ['processing_status'],
            ),
            'unknown' => $this->metric(
                Message::query()->where('classification', MessageClassification::Unknown)->count(),
                ['classification' => MessageClassification::Unknown->value],
                $currentFilter,
                ['classification'],
            ),
            'ignored' => [
                'value' => Message::query()->where('classification', MessageClassification::Ignored)->count(),
                'url' => route('platform.messages.ignored'),
                'active' => false,
            ],
            'requires_manager' => $this->metric(
                Message::query()->where('requires_manager', true)->count(),
                ['requires_manager' => '1'],
                $currentFilter,
                ['requires_manager'],
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $currentFilter
     * @return array<string, array{value: int, url: string, active?: bool}>
     */
    private function inboxMetricsExtra(array $currentFilter): array
    {
        return [
            'client' => $this->metric(
                Message::query()->where('classification', MessageClassification::Client)->count(),
                ['classification' => MessageClassification::Client->value],
                $currentFilter,
                ['classification'],
            ),
            'site_lead' => $this->metric(
                Message::query()->where('classification', MessageClassification::SiteLead)->count(),
                ['classification' => MessageClassification::SiteLead->value],
                $currentFilter,
                ['classification'],
            ),
            'technical' => $this->metric(
                Message::query()->where('classification', MessageClassification::Technical)->count(),
                ['classification' => MessageClassification::Technical->value],
                $currentFilter,
                ['classification'],
            ),
            'no_project' => $this->metric(
                Message::query()->whereNull('project_id')->count(),
                ['project_id' => 'none'],
                $currentFilter,
                ['project_id'],
            ),
            'failed' => $this->metric(
                Message::query()->where('processing_status', MessageProcessingStatus::Failed)->count(),
                ['processing_status' => MessageProcessingStatus::Failed->value],
                $currentFilter,
                ['processing_status'],
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $filter
     * @param  array<string, mixed>  $currentFilter
     * @param  list<string>  $activeKeys
     * @return array{value: int, url: string, active: bool}
     */
    private function metric(int $value, array $filter, array $currentFilter, array $activeKeys): array
    {
        $active = $activeKeys === []
            ? $this->isFilterEmpty($currentFilter)
            : $this->isFilterActive($currentFilter, $filter, $activeKeys);

        return [
            'value' => $value,
            'url' => $filter === []
                ? route('platform.messages')
                : route('platform.messages', ['filter' => $filter]),
            'active' => $active,
        ];
    }

    /**
     * @param  array<string, mixed>  $filter
     */
    private function isFilterEmpty(array $filter): bool
    {
        foreach ($filter as $value) {
            if ($value !== null && $value !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $expected
     * @param  list<string>  $keys
     */
    private function isFilterActive(array $current, array $expected, array $keys): bool
    {
        foreach ($keys as $key) {
            $currentValue = (string) ($current[$key] ?? '');
            $expectedValue = (string) ($expected[$key] ?? '');
            if ($currentValue !== $expectedValue) {
                return false;
            }
        }

        $relevant = array_filter(
            $current,
            static fn (mixed $value): bool => $value !== null && $value !== '',
        );

        return count($relevant) === count($expected)
            && collect($expected)->every(
                static fn (mixed $value, string $key): bool => (string) ($relevant[$key] ?? '') === (string) $value,
            );
    }
}
