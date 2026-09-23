<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Message;

use App\Models\Message;
use Illuminate\Support\Str;
use Orchid\Screen\Layouts\Table;
use Orchid\Screen\TD;

class MessageListLayout extends Table
{
    protected $target = 'messages';

    protected function columns(): iterable
    {
        return [
            TD::make('id', 'ID')
                ->width('70')
                ->cantHide()
                ->style('vertical-align: top')
                ->render(fn (Message $message) => '<a href="'.e(route('platform.messages.view', $message)).'">'
                    .e((string) $message->id)
                    .'</a>'),
            TD::make('sender', 'Отправитель')
                ->width('240')
                ->style('vertical-align: top')
                ->render(fn (Message $message) => '<div style="word-break:break-word;">'
                    .e((string) $message->sender)
                    .'</div>'),
            TD::make('subject', 'Тема')
                ->style('vertical-align: top')
                ->render(fn (Message $message) => $this->renderMessageCell($message)),
        ];
    }

    private function renderMessageCell(Message $message): string
    {
        $url = e(route('platform.messages.view', $message));
        $subject = e((string) ($message->subject ?: '—'));
        $date = e($message->received_at?->format('d.m.Y H:i') ?? '—');

        $summary = trim((string) ($message->ai_summary ?? ''));
        $summaryHtml = $summary !== ''
            ? e(Str::limit($summary, 220))
            : '<span class="text-muted">—</span>';

        return <<<HTML
<div style="display:flex;flex-direction:column;gap:0.45rem;min-width:0;">
  <div style="display:flex;flex-wrap:wrap;align-items:baseline;gap:0.5rem;">
    <a href="{$url}">{$subject}</a>
    <span class="text-muted" style="font-size:0.85em;white-space:nowrap;">{$date}</span>
  </div>
  <div style="color:#6c757d;font-size:0.9em;line-height:1.35;">{$summaryHtml}</div>
  <div style="display:flex;flex-wrap:wrap;gap:0.35rem;">{$this->metaTags($message)}</div>
</div>
HTML;
    }

    private function metaTags(Message $message): string
    {
        $tags = [];

        $tags[] = $this->metaPill('Классификация', $message->classification?->label() ?? '—');

        if ($message->project !== null) {
            $tags[] = $this->metaPill(
                'Проект',
                $message->project->name,
                route('platform.projects.edit', $message->project),
            );
        } else {
            $tags[] = $this->metaPill('Проект', '—');
        }

        if ($message->projectTask !== null) {
            $tags[] = $this->metaPill(
                'Задача',
                $message->projectTask->displayLabel(),
                route('platform.tasks.view', $message->projectTask),
            );
        } else {
            $tags[] = $this->metaPill('Задача', '—');
        }

        $tags[] = $this->metaPill(
            'Событие',
            $message->events->first()?->type->label() ?? '—',
            $message->events->isNotEmpty()
                ? route('platform.messages.view', $message)
                : null,
        );
        $tags[] = $this->metaPill(
            'Менеджер',
            $message->requires_manager === null
                ? '—'
                : ($message->requires_manager ? 'да' : 'нет'),
        );
        $tags[] = $this->metaPill('Статус', $message->processing_status->label());
        $tags[] = $this->metaPill(
            'Уверенность',
            $message->ai_confidence !== null
                ? (string) $message->ai_confidence
                : '—',
        );

        return implode('', $tags);
    }

    private function metaPill(string $label, string $value, ?string $url = null): string
    {
        $inner = e($label).': '.e($value);
        if ($url !== null && $url !== '') {
            $inner = e($label).': <a class="inbox-meta-link" href="'.e($url)
                .'" style="color:inherit!important;text-decoration:none!important;border-bottom:0!important;">'
                .e($value)
                .'</a>';
        }

        return sprintf(
            '<span title="%1$s" style="display:inline-block;background:#eef1f4;color:#495057;border-radius:999px;padding:0.15rem 0.65rem;font-size:0.78rem;line-height:1.4;white-space:nowrap;">%2$s</span>',
            e($label),
            $inner,
        );
    }
}
