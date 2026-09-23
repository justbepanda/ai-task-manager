<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\ManagerAction;

use App\Models\ManagerAction;
use Illuminate\Support\Str;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Layouts\Table;
use Orchid\Screen\TD;

class ManagerActionListLayout extends Table
{
    protected $target = 'actions';

    protected function columns(): iterable
    {
        return [
            TD::make('id', 'ID')
                ->width('56')
                ->style('vertical-align: top; padding-top: 0.4rem; padding-bottom: 0.4rem;')
                ->render(function (ManagerAction $action) {
                    $url = e(route('platform.manager-actions.view', $action));

                    return '<a href="'.$url.'" class="text-decoration-none">'.e((string) $action->id).'</a>';
                }),
            TD::make('project', 'Проект')
                ->width('140')
                ->style('vertical-align: top; padding-top: 0.4rem; padding-bottom: 0.4rem;')
                ->render(function (ManagerAction $action) {
                    if ($action->project === null) {
                        return '—';
                    }

                    $url = e(route('platform.projects.edit', $action->project));

                    return '<a href="'.$url.'" class="text-decoration-none">'
                        .e($action->project->name)
                        .'</a>';
                }),
            TD::make('message', 'Письмо / событие')
                ->style('vertical-align: top; padding-top: 0.4rem; padding-bottom: 0.4rem;')
                ->render(fn (ManagerAction $action) => $this->renderMessageEventCell($action)),
            TD::make('action_type', 'Действие')
                ->width('110')
                ->style('vertical-align: top; padding-top: 0.4rem; padding-bottom: 0.4rem;')
                ->render(fn (ManagerAction $action) => e($action->action_type->label())),
            TD::make('status', 'Статус')
                ->width('100')
                ->style('vertical-align: top; padding-top: 0.4rem; padding-bottom: 0.4rem;')
                ->render(fn (ManagerAction $action) => e($action->status->label())),
            TD::make('open', '')
                ->width('90')
                ->style('vertical-align: top; padding-top: 0.35rem; padding-bottom: 0.35rem;')
                ->render(fn (ManagerAction $action) => Link::make('Открыть')
                    ->route('platform.manager-actions.view', $action)),
        ];
    }

    private function renderMessageEventCell(ManagerAction $action): string
    {
        $messageId = $action->message_id ?? $action->event?->message_id;
        $messageUrl = $messageId !== null
            ? e(route('platform.messages.view', $messageId))
            : null;

        $subject = trim((string) ($action->message?->subject ?? ''));
        if ($messageId !== null) {
            $letter = '#'.$messageId;
            if ($subject !== '') {
                // ~4× прежнего лимита (40 → 160).
                $letter .= ' '.Str::limit($subject, 160);
            }
        } else {
            $letter = '—';
        }

        $event = $action->event;
        if ($event !== null) {
            $eventText = $event->type->label();
            $summary = trim((string) ($event->summary ?? ''));
            if ($summary !== '') {
                $eventText .= ': '.$summary;
            }
        } else {
            $eventText = '—';
        }

        $letterHtml = $messageUrl !== null
            ? '<a href="'.$messageUrl.'" class="text-decoration-none" style="color:inherit;">'.e($letter).'</a>'
            : e($letter);

        $eventHtml = $messageUrl !== null
            ? '<a href="'.$messageUrl.'" class="text-decoration-none" style="color:#6c757d;">'.e($eventText).'</a>'
            : '<span style="color:#6c757d;">'.e($eventText).'</span>';

        return <<<HTML
<div style="display:flex;flex-direction:column;gap:0.2rem;line-height:1.35;min-width:0;">
  <div style="word-break:break-word;">{$letterHtml}</div>
  <div style="font-size:0.9em;word-break:break-word;">{$eventHtml}</div>
</div>
HTML;
    }
}
