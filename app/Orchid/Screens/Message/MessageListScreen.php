<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Message;

use App\Enums\MessageClassification;
use App\Models\Message;
use App\Orchid\Layouts\Message\MessageFiltersLayout;
use App\Orchid\Layouts\Message\MessageListLayout;
use Illuminate\Http\Request;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Screen;

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
            ->with(['project', 'events'])
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
            return [];
        }

        return [
            Link::make('Распределить по проектам')
                ->icon('bs.diagram-3')
                ->route('platform.messages.assign'),
        ];
    }

    public function layout(): iterable
    {
        return [
            MessageFiltersLayout::class,
            MessageListLayout::class,
        ];
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
