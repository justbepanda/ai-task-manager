<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Message;

use App\Domain\Mail\MailFilterSettingService;
use App\Domain\Messages\MessageManualOverrideService;
use App\Domain\Messages\ReanalyzeMessage;
use App\Domain\Messages\ResolveMessageSenderPerson;
use App\Enums\MessageClassification;
use App\Hermes\ProcessIncomingMessage;
use App\Jobs\AnalyzeMessageJob;
use App\Models\AnalysisCorrection;
use App\Models\Message;
use App\Models\ProcessingLog;
use App\Models\Project;
use App\Support\AdminLabels;
use App\Support\EmailAddress;
use App\Support\EmailLatestReplyExtractor;
use App\Support\SensitiveDataSanitizer;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\Select;
use Orchid\Screen\Screen;
use Orchid\Screen\Sight;
use Orchid\Screen\TD;
use Orchid\Support\Color;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;

class MessageViewScreen extends Screen
{
    public ?Message $message = null;

    /** @var array{type: string, id: int, name: string, route: string, label: string}|null */
    public ?array $senderPerson = null;

    public function query(Message $message, ResolveMessageSenderPerson $resolver): iterable
    {
        $message->load(['project', 'events', 'analysisCorrections', 'processingLogs']);
        $this->senderPerson = $resolver->resolve($message->sender);

        return [
            'message' => $message,
            'senderPerson' => $this->senderPerson,
            'processingLogs' => $message->processingLogs->sortBy('created_at')->values(),
            'analysisCorrections' => $message->analysisCorrections->sortBy('created_at')->values(),
            'override' => [
                'classification' => $message->classification?->value,
                'project_id' => $message->project_id,
            ],
        ];
    }

    public function name(): ?string
    {
        return 'Сообщение #'.($this->message?->id ?? '');
    }

    public function description(): ?string
    {
        return $this->message?->subject;
    }

    public function commandBar(): iterable
    {
        return [
            Link::make('К списку')->icon('bs.arrow-left')->route('platform.messages'),
            Button::make('В чёрный список')
                ->icon('bs.ban')
                ->method('addToBlacklist')
                ->type(Color::WARNING)
                ->confirm('Добавить email отправителя в чёрный список и переклассифицировать письмо как «Игнорируемое»?'),
            Button::make('Переанализировать')
                ->icon('bs.stars')
                ->method('reanalyze')
                ->type(Color::SECONDARY)
                ->confirm('Удалить текущее событие/действие менеджера и заново запустить Hermes по этому письму?'),
            Button::make('Сохранить правки')
                ->icon('bs.check-circle')
                ->method('saveOverride')
                ->type(Color::PRIMARY),
        ];
    }

    public function layout(): iterable
    {
        return [
            Layout::legend('message', [
                Sight::make('sender', 'Отправитель')->render(function (Message $m) {
                    $person = $this->senderPerson;

                    return view('orchid.fields.sender-with-link', [
                        'sender' => (string) $m->sender,
                        'editUrl' => is_array($person) ? $person['route'] : null,
                        'editLabel' => is_array($person) ? $person['label'] : null,
                    ]);
                }),
                Sight::make('subject', 'Тема'),
                Sight::make('received_at', 'Дата')->render(fn (Message $m) => $m->received_at?->format('d.m.Y H:i:s') ?? '—'),
                Sight::make('processing_status', 'Статус')->render(fn (Message $m) => e($m->processing_status->label())),
                Sight::make('ai_summary', 'Краткое описание')->render(fn (Message $m) => e((string) ($m->ai_summary ?: '—'))),
                Sight::make('ai_confidence', 'Уверенность')->render(fn (Message $m) => $m->ai_confidence !== null ? e((string) $m->ai_confidence) : '—'),
                Sight::make('requires_manager', 'Требуется менеджер')->render(fn (Message $m) => $m->requires_manager === null ? '—' : ($m->requires_manager ? 'да' : 'нет')),
                Sight::make('event_type', 'Тип события')->render(fn (Message $m) => e($m->events->first()?->type->label() ?? '—')),
                Sight::make('project_candidates', 'Кандидаты проектов')->render(function (Message $m) {
                    $match = is_array($m->metadata) ? ($m->metadata['project_match'] ?? null) : null;
                    if (! is_array($match)) {
                        return '—';
                    }

                    $ambiguous = ! empty($match['ambiguous']) ? ' (неоднозначно)' : '';
                    $candidates = is_array($match['candidates'] ?? null) ? $match['candidates'] : [];
                    if ($candidates === []) {
                        $reasons = is_array($match['reasons'] ?? null) ? implode(', ', $match['reasons']) : '';

                        return e('нет кандидатов'.$ambiguous.($reasons !== '' ? ' — '.$reasons : ''));
                    }

                    $projectIds = [];
                    foreach ($candidates as $c) {
                        if (is_array($c) && isset($c['project_id'])) {
                            $projectIds[] = (int) $c['project_id'];
                        }
                    }
                    $names = Project::query()
                        ->whereIn('id', $projectIds)
                        ->pluck('name', 'id');

                    $lines = [];
                    foreach ($candidates as $c) {
                        if (! is_array($c) || ! isset($c['project_id'])) {
                            continue;
                        }
                        $id = (int) $c['project_id'];
                        $score = isset($c['score']) ? number_format((float) $c['score'], 2) : '?';
                        $reasons = is_array($c['reasons'] ?? null) ? implode(', ', $c['reasons']) : '';
                        $name = $names[$id] ?? ('#'.$id);
                        $lines[] = sprintf('%s — score %s%s', $name, $score, $reasons !== '' ? ' ('.$reasons.')' : '');
                    }

                    return e(implode('; ', $lines).$ambiguous);
                }),
                Sight::make('ai_latest_body', 'Для AI (последний ответ)')->render(function (Message $m) {
                    $latest = app(EmailLatestReplyExtractor::class)->latest((string) ($m->body ?? ''), 2000);

                    return view('orchid.fields.plain-text', [
                        'text' => $latest !== '' ? $latest : '—',
                    ]);
                }),
                Sight::make('body', 'Текст письма')->render(fn (Message $m) => view('orchid.fields.plain-text', [
                    'text' => (string) ($m->body ?? ''),
                ])),
            ]),

            Layout::rows([
                Select::make('override.classification')
                    ->title('Классификация')
                    ->empty('Не задано')
                    ->options(MessageClassification::options()),

                Select::make('override.project_id')
                    ->title('Проект')
                    ->empty('Без проекта')
                    ->options(Project::query()->orderBy('name')->pluck('name', 'id')->all()),

                Input::make('message.id')->type('hidden'),
            ])->title('Изменить'),

            Layout::table('processingLogs', [
                TD::make('step', 'Шаг')->width('180')
                    ->render(fn (ProcessingLog $log) => e(AdminLabels::processingStep((string) $log->step))),
                TD::make('status', 'Статус')->width('140')
                    ->render(fn (ProcessingLog $log) => e(AdminLabels::processingStatus((string) $log->status))),
                TD::make('created_at', 'Время')->width('160')
                    ->render(fn (ProcessingLog $log) => $log->created_at?->format('d.m.Y H:i:s') ?? '—'),
                TD::make('details', 'Детали')
                    ->render(fn (ProcessingLog $log) => e(SensitiveDataSanitizer::summary(
                        SensitiveDataSanitizer::sanitizeDetails(
                            is_array($log->details) ? $log->details : null,
                        ),
                    ))),
            ])->title('Журнал обработки'),

            Layout::table('analysisCorrections', [
                TD::make('field', 'Поле')->width('180')
                    ->render(fn (AnalysisCorrection $c) => e(AdminLabels::correctionField((string) $c->field))),
                TD::make('ai_value', 'Значение AI')
                    ->render(fn (AnalysisCorrection $c) => e(\Illuminate\Support\Str::limit((string) ($c->ai_value ?? '—'), 80))),
                TD::make('manager_value', 'Значение менеджера')
                    ->render(fn (AnalysisCorrection $c) => e(\Illuminate\Support\Str::limit((string) ($c->manager_value ?? '—'), 80))),
                TD::make('created_at', 'Время')->width('160')
                    ->render(fn (AnalysisCorrection $c) => $c->created_at?->format('d.m.Y H:i:s') ?? '—'),
            ])->title('Исправления анализа'),
        ];
    }

    public function saveOverride(Request $request, Message $message, MessageManualOverrideService $service): void
    {
        $validated = $request->validate([
            'override.classification' => ['nullable', Rule::enum(MessageClassification::class)],
            'override.project_id' => ['nullable', 'integer', 'exists:projects,id'],
        ]);

        $data = $validated['override'] ?? [];

        $message = $service->apply($message, [
            'classification' => $data['classification'] ?? null,
            'project_id' => array_key_exists('project_id', $data)
                ? ($data['project_id'] !== null && $data['project_id'] !== '' ? (int) $data['project_id'] : null)
                : $message->project_id,
        ]);

        if ($service->shouldQueueAnalyze($message)) {
            AnalyzeMessageJob::dispatch($message->id);
            Toast::info('Сохранено. AI-анализ поставлен в очередь.');

            return;
        }

        Toast::info('Корректировки сохранены.');
    }

    public function reanalyze(Message $message, ReanalyzeMessage $reanalyze): void
    {
        $reanalyze->handle($message, sync: false);
        Toast::info('Переанализ поставлен в очередь. Обновите страницу через минуту.');
    }

    public function addToBlacklist(
        Message $message,
        MailFilterSettingService $filters,
        ProcessIncomingMessage $process,
    ): void {
        $email = EmailAddress::extract($message->sender);
        if ($email === null) {
            Toast::warning('Не удалось извлечь email отправителя.');

            return;
        }

        $filters->addBlockedEmail($email);

        $process->handle($message->fresh());

        Toast::info('Отправитель '.$email.' добавлен в чёрный список. Письмо переклассифицировано.');
    }
}
