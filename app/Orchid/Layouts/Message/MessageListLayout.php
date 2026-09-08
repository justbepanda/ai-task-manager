<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Message;

use App\Models\Message;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Layouts\Table;
use Orchid\Screen\TD;

class MessageListLayout extends Table
{
    protected $target = 'messages';

    protected function columns(): iterable
    {
        return [
            TD::make('id', 'ID')->width('70'),
            TD::make('sender', 'Отправитель')
                ->render(fn (Message $message) => e((string) $message->sender)),
            TD::make('subject', 'Тема')
                ->render(fn (Message $message) => Link::make((string) ($message->subject ?: '—'))
                    ->route('platform.messages.view', $message)),
            TD::make('received_at', 'Дата')
                ->render(fn (Message $message) => $message->received_at?->format('d.m.Y H:i')),
            TD::make('classification', 'Классификация')
                ->render(fn (Message $message) => e($message->classification?->label() ?? '—')),
            TD::make('project', 'Проект')
                ->render(fn (Message $message) => e($message->project?->name ?? '—')),
            TD::make('ai_summary', 'Краткое описание')
                ->render(fn (Message $message) => e(\Illuminate\Support\Str::limit((string) $message->ai_summary, 50))),
            TD::make('event_type', 'Событие')
                ->render(fn (Message $message) => e($message->events->first()?->type->label() ?? '—')),
            TD::make('requires_manager', 'Менеджер')
                ->render(fn (Message $message) => $message->requires_manager === null
                    ? '—'
                    : ($message->requires_manager ? 'да' : 'нет')),
            TD::make('processing_status', 'Статус')
                ->render(fn (Message $message) => e($message->processing_status->label())),
            TD::make('ai_confidence', 'Уверенность')
                ->render(fn (Message $message) => $message->ai_confidence !== null ? (string) $message->ai_confidence : '—'),
        ];
    }
}
