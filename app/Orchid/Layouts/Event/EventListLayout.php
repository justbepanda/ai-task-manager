<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Event;

use App\Models\Event;
use Illuminate\Support\Str;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Layouts\Table;
use Orchid\Screen\TD;

class EventListLayout extends Table
{
    protected $target = 'events';

    public function __construct(?string $target = null)
    {
        if ($target !== null) {
            $this->target = $target;
        }
    }

    protected function columns(): iterable
    {
        return [
            TD::make('id', 'ID')
                ->width('70')
                ->style('vertical-align: top; padding-top: 0.4rem; padding-bottom: 0.4rem;')
                ->render(function (Event $event) {
                    if ($event->message_id === null) {
                        return Link::make((string) $event->id)
                            ->route('platform.events.view', $event);
                    }

                    return Link::make((string) $event->id)
                        ->route('platform.messages.view', $event->message_id);
                }),
            TD::make('type', 'Тип')
                ->width('160')
                ->style('vertical-align: top; padding-top: 0.4rem; padding-bottom: 0.4rem;')
                ->render(fn (Event $event) => e($event->type?->label() ?? '—')),
            TD::make('summary', 'Краткое описание')
                ->style('vertical-align: top; padding-top: 0.4rem; padding-bottom: 0.4rem; word-break: break-word; white-space: normal;')
                ->render(function (Event $event) {
                    $summary = trim((string) ($event->summary ?? ''));
                    if ($summary === '') {
                        return '—';
                    }

                    if ($event->message_id === null) {
                        return e($summary);
                    }

                    return Link::make($summary)
                        ->route('platform.messages.view', $event->message_id);
                }),
            TD::make('project', 'Проект')
                ->width('160')
                ->style('vertical-align: top; padding-top: 0.4rem; padding-bottom: 0.4rem;')
                ->render(function (Event $event) {
                    if ($event->project === null) {
                        return '—';
                    }

                    return Link::make($event->project->name)
                        ->route('platform.projects.edit', $event->project);
                }),
            TD::make('message', 'Письмо')
                ->width('180')
                ->style('vertical-align: top; padding-top: 0.4rem; padding-bottom: 0.4rem;')
                ->render(function (Event $event) {
                    if ($event->message_id === null) {
                        return '—';
                    }

                    $label = '#'.$event->message_id;
                    $subject = trim((string) ($event->message?->subject ?? ''));
                    if ($subject !== '') {
                        $label .= ' '.Str::limit($subject, 40);
                    }

                    return Link::make($label)
                        ->route('platform.messages.view', $event->message_id);
                }),
            TD::make('confidence', 'Уверенность')
                ->width('100')
                ->style('vertical-align: top; padding-top: 0.4rem; padding-bottom: 0.4rem;')
                ->render(fn (Event $event) => e($event->confidence !== null ? (string) $event->confidence : '—')),
            TD::make('applied_at', 'Применено')
                ->width('130')
                ->style('vertical-align: top; padding-top: 0.4rem; padding-bottom: 0.4rem;')
                ->render(fn (Event $event) => e($event->applied_at?->format('d.m.Y H:i') ?? '—')),
            TD::make('created_at', 'Создано')
                ->width('130')
                ->style('vertical-align: top; padding-top: 0.4rem; padding-bottom: 0.4rem;')
                ->render(fn (Event $event) => e($event->created_at?->format('d.m.Y H:i') ?? '—')),
            TD::make('open', '')
                ->width('90')
                ->style('vertical-align: top; padding-top: 0.35rem; padding-bottom: 0.35rem;')
                ->render(fn (Event $event) => Link::make('Открыть')
                    ->route('platform.events.view', $event)),
        ];
    }
}
