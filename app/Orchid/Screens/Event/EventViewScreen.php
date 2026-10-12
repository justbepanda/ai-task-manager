<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Event;

use App\Models\Event;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Screen;
use Orchid\Screen\Sight;
use Orchid\Support\Facades\Layout;

class EventViewScreen extends Screen
{
    public ?Event $event = null;

    public function query(Event $event): iterable
    {
        $event->load(['project', 'message', 'managerActions']);
        $this->event = $event;

        return [
            'event' => $event,
        ];
    }

    public function name(): ?string
    {
        return 'Событие #'.($this->event?->id ?? '');
    }

    public function description(): ?string
    {
        return $this->event?->summary;
    }

    public function commandBar(): iterable
    {
        return [
            Link::make('К списку')->icon('bs.arrow-left')->route('platform.events'),
            Link::make('Письмо')
                ->icon('bs.envelope')
                ->route('platform.messages.view', $this->event?->message_id)
                ->canSee($this->event?->message_id !== null),
            Link::make('Проект')
                ->icon('bs.folder2')
                ->route('platform.projects.edit', $this->event?->project_id)
                ->canSee($this->event?->project_id !== null),
        ];
    }

    public function layout(): iterable
    {
        return [
            Layout::legend('event', [
                Sight::make('id', 'ID'),
                Sight::make('type', 'Тип')->render(fn (Event $e) => e($e->type?->label() ?? '—')),
                Sight::make('summary', 'Краткое описание'),
                Sight::make('project', 'Проект')->render(function (Event $e) {
                    if ($e->project === null) {
                        return '—';
                    }

                    return Link::make($e->project->name)
                        ->route('platform.projects.edit', $e->project);
                }),
                Sight::make('message', 'Письмо')->render(function (Event $e) {
                    if ($e->message_id === null) {
                        return '—';
                    }

                    $label = '#'.$e->message_id;
                    $subject = trim((string) ($e->message?->subject ?? ''));
                    if ($subject !== '') {
                        $label .= ' '.$subject;
                    }

                    return Link::make($label)
                        ->route('platform.messages.view', $e->message_id);
                }),
                Sight::make('confidence', 'Уверенность'),
                Sight::make('applied_at', 'Применено')
                    ->render(fn (Event $e) => e($e->applied_at?->format('d.m.Y H:i') ?? '—')),
                Sight::make('created_at', 'Создано')
                    ->render(fn (Event $e) => e($e->created_at?->format('d.m.Y H:i') ?? '—')),
                Sight::make('payload', 'Payload')
                    ->render(function (Event $e) {
                        $payload = $e->payload;
                        if (! is_array($payload) || $payload === []) {
                            return '—';
                        }

                        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

                        return '<pre style="white-space:pre-wrap;margin:0;">'.e((string) $json).'</pre>';
                    }),
            ]),
        ];
    }
}
