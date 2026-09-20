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
                ->render(fn (Message $message) => e((string) $message->id)),
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
        $items = [
            'Классификация' => $message->classification?->label() ?? '—',
            'Проект' => $message->project?->name ?? '—',
            'Событие' => $message->events->first()?->type->label() ?? '—',
            'Менеджер' => $message->requires_manager === null
                ? '—'
                : ($message->requires_manager ? 'да' : 'нет'),
            'Статус' => $message->processing_status->label(),
            'Уверенность' => $message->ai_confidence !== null
                ? (string) $message->ai_confidence
                : '—',
        ];

        $tags = [];
        foreach ($items as $label => $value) {
            $tags[] = sprintf(
                '<span title="%1$s" style="display:inline-block;background:#eef1f4;color:#495057;border-radius:999px;padding:0.15rem 0.65rem;font-size:0.78rem;line-height:1.4;white-space:nowrap;">%1$s: %2$s</span>',
                e($label),
                e($value),
            );
        }

        return implode('', $tags);
    }
}
