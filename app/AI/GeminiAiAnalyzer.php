<?php

namespace App\AI;

use App\AI\Contracts\AiAnalyzer;
use App\AI\DTO\MessageContext;
use App\AI\Exceptions\AnalysisException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class GeminiAiAnalyzer implements AiAnalyzer
{
    /** @var list<int> */
    private const RETRYABLE_STATUSES = [408, 429, 500, 502, 503, 504];

    private ?string $lastModel = null;

    /**
     * Model that produced the last successful response (or last attempted on failure).
     */
    public function lastModel(): ?string
    {
        return $this->lastModel;
    }

    /**
     * @return array<string, mixed>
     */
    public function analyze(MessageContext $context): array
    {
        return $this->completeJson(
            $this->systemPrompt($context),
            $this->userPrompt($context),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function completeJson(string $systemPrompt, string $userPrompt): array
    {
        $apiKey = config('ai.gemini.api_key');
        $timeout = (int) config('ai.gemini.timeout', 60);
        $baseUrl = rtrim((string) config('ai.gemini.base_url'), '/');
        $maxAttempts = max(1, (int) config('ai.gemini.retry.max_attempts', 3));
        $baseDelayMs = max(0, (int) config('ai.gemini.retry.base_delay_ms', 800));

        if (! is_string($apiKey) || $apiKey === '') {
            throw new AnalysisException('Gemini API key is not configured', 'config_error');
        }

        $models = $this->modelCandidates();
        $lastStatus = null;
        $lastException = null;
        $this->lastModel = null;

        foreach ($models as $modelIndex => $model) {
            $this->lastModel = $model;
            $url = "{$baseUrl}/models/{$model}:generateContent";
            $hasFallback = $modelIndex < count($models) - 1;

            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                try {
                    $response = Http::timeout($timeout)
                        ->withHeaders([
                            'x-goog-api-key' => $apiKey,
                            'Content-Type' => 'application/json',
                        ])
                        ->post($url, $this->requestBody($systemPrompt, $userPrompt, $model));
                } catch (ConnectionException $e) {
                    $lastException = $e;
                    Log::warning('Gemini API connection failed', [
                        'model' => $model,
                        'attempt' => $attempt,
                    ]);

                    if ($attempt < $maxAttempts) {
                        $this->sleepBeforeRetry($baseDelayMs, $attempt);

                        continue;
                    }

                    if ($hasFallback) {
                        continue 2;
                    }

                    throw new AnalysisException(
                        'Gemini временно недоступен (сеть). Повторите через минуту.',
                        'http_error',
                        $e,
                    );
                } catch (Throwable $e) {
                    throw new AnalysisException('Gemini API request failed', 'http_error', $e);
                }

                if ($response->successful()) {
                    return $this->parseSuccessfulResponse($response);
                }

                $lastStatus = $response->status();
                Log::warning('Gemini API HTTP error', [
                    'status' => $lastStatus,
                    'model' => $model,
                    'attempt' => $attempt,
                ]);

                // Rate limit: switch to fallback immediately (retries won't clear quota).
                if ($lastStatus === 429 && $hasFallback) {
                    Log::info('Gemini rate limited; switching to fallback model', [
                        'from' => $model,
                        'to' => $models[$modelIndex + 1],
                    ]);

                    continue 2;
                }

                // Unknown/retired model id — try next candidate.
                if ($lastStatus === 404 && $hasFallback) {
                    continue 2;
                }

                $retryable = in_array($lastStatus, self::RETRYABLE_STATUSES, true);
                if ($retryable && $attempt < $maxAttempts) {
                    $this->sleepBeforeRetry($baseDelayMs, $attempt);

                    continue;
                }

                if ($retryable && $hasFallback) {
                    continue 2;
                }

                throw new AnalysisException(
                    $this->userFacingHttpError($lastStatus, $models),
                    'http_error',
                );
            }
        }

        throw new AnalysisException(
            $this->userFacingHttpError($lastStatus, $models),
            'http_error',
            $lastException,
        );
    }

    /**
     * @return list<string>
     */
    private function modelCandidates(): array
    {
        $primary = (string) config('ai.gemini.model', 'gemini-3.6-flash');
        $fallbacks = config('ai.gemini.fallback_models', []);
        if (! is_array($fallbacks)) {
            $fallbacks = [];
        }

        $models = [$primary];
        foreach ($fallbacks as $fallback) {
            if (! is_string($fallback) || $fallback === '' || in_array($fallback, $models, true)) {
                continue;
            }
            $models[] = $fallback;
        }

        return $models;
    }

    /**
     * @return array<string, mixed>
     */
    private function requestBody(string $systemPrompt, string $userPrompt, string $model): array
    {
        $generationConfig = [
            'temperature' => 0.2,
            'responseMimeType' => 'application/json',
        ];

        $thinkingLevel = config('ai.gemini.thinking_level');
        if (
            is_string($thinkingLevel)
            && $thinkingLevel !== ''
            && str_starts_with($model, 'gemini-3')
        ) {
            $generationConfig['thinkingConfig'] = [
                'thinkingLevel' => $thinkingLevel,
            ];
        }

        return [
            'system_instruction' => [
                'parts' => [
                    ['text' => $systemPrompt],
                ],
            ],
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $userPrompt],
                    ],
                ],
            ],
            'generationConfig' => $generationConfig,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseSuccessfulResponse(Response $response): array
    {
        $text = data_get($response->json(), 'candidates.0.content.parts.0.text');
        if (! is_string($text) || trim($text) === '') {
            throw new AnalysisException('Gemini API returned empty content', 'parse_error');
        }

        return $this->parseJsonPayload($text);
    }

    private function sleepBeforeRetry(int $baseDelayMs, int $attempt): void
    {
        if ($baseDelayMs <= 0) {
            return;
        }

        $delayMs = (int) ($baseDelayMs * (2 ** ($attempt - 1)));
        $jitterMs = random_int(0, (int) max(1, $baseDelayMs / 2));
        usleep(($delayMs + $jitterMs) * 1000);
    }

    private function userFacingHttpError(?int $status, array $models = []): string
    {
        $modelsHint = $models !== []
            ? ' Пробовали: '.implode(', ', $models).'.'
            : '';

        if ($status === 429) {
            return 'Gemini перегружен (лимит запросов).'.$modelsHint.' Подождите минуту и нажмите «Запросить» снова.';
        }

        if ($status === 404) {
            return 'Gemini: модель недоступна (HTTP 404). Проверьте GEMINI_MODEL / GEMINI_FALLBACK_MODELS.';
        }

        if ($status !== null && $status >= 500) {
            return 'Gemini временно недоступен (HTTP '.$status.'). Это обычно проходит само — повторите через минуту.';
        }

        if ($status !== null) {
            return 'Gemini API returned HTTP '.$status;
        }

        return 'Gemini API request failed';
    }

    /**
     * @return array<string, mixed>
     */
    private function parseJsonPayload(string $text): array
    {
        $trimmed = trim($text);

        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $trimmed, $matches) === 1) {
            $trimmed = trim($matches[1]);
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new AnalysisException('Gemini returned invalid JSON', 'parse_error', $e);
        }

        if (! is_array($decoded)) {
            throw new AnalysisException('Gemini JSON root must be an object', 'parse_error');
        }

        return $decoded;
    }

    private function systemPrompt(MessageContext $context): string
    {
        $candidateIds = $context->candidateProjectIds === []
            ? '(none — project_id must be null)'
            : implode(', ', $context->candidateProjectIds);

        $candidateProjects = $context->candidateProjects === []
            ? '(none)'
            : collect($context->candidateProjects)
                ->map(static fn (array $p): string => ($p['id'] ?? '?').': '.($p['name'] ?? ''))
                ->implode('; ');

        $redmineRule = '';
        if (($context->matchingHints['source'] ?? null) === 'redmine_subject') {
            $names = $context->matchingHints['subject_project_names'] ?? [];
            $namesList = is_array($names) && $names !== []
                ? implode(' | ', array_map(static fn ($n) => (string) $n, $names))
                : '(не удалось разобрать тему)';
            $redmineRule = "\n- Письмо от Redmine: название проекта определяй В ПЕРВУЮ ОЧЕРЕДЬ по теме. "
                .'В [квадратных скобках] до « - Tracker #номер» — имя/алиасы проекта (через запятую). '
                .'Пример: «[Tekta, Текта, … - Bug #1]» → Tekta; «[RBI сайт 2025 - New #99143]» → RBI сайт 2025. '
                .'Сопоставь с candidate_projects по имени. Извлечённые из темы имена: '.$namesList.'.';
        }

        $modeRule = '';
        if ($context->analysisMode === 'technical_summary') {
            $modeRule = "\n- Режим technical_summary: это техническое уведомление (часто Redmine/Jira). "
                .'event_type обычно technical_notification или task_updated/task_created. '
                .'summary — ОЧЕНЬ коротко: кто + суть изменения (1 предложение, до ~120 символов). '
                .'Не пиши «Задача обновлена…», номер тикета, полное название и смену статуса — они уже в теме/карточке. '
                .'Плохо: «Задача обновлена: Владимир Андреев обновил задачу Redmine #99533 «ЛК Брокера…», переведя ее в статус «Обратная связь», и сообщил о заливке на дев и стейдж.» '
                .'Хорошо: «Владимир Андреев сообщил о заливке на дев и стейдж.» '
                .'suggested_action обычно no_action, draft_reply=null, requires_manager=false — '
                .'кроме: (1) в теме есть «(Обратная связь)» → requires_manager=true, suggested_action=review; '
                .'(2) явная критичность (падение, блокер) → review.';
        }

        $correctionsRule = '';
        if ($context->analysisCorrections !== [] || $context->recentProjectCorrections !== []) {
            $correctionsRule = "\n- Учти analysis_corrections / recent_project_corrections: менеджер раньше правил AI — "
                .'не повторяй типичные ошибки (проект, классификация, тон draft).';
        }

        $prompt = <<<PROMPT
Ты — помощник менеджера проектов. Анализируй входящие письма и возвращай ТОЛЬКО валидный JSON без markdown.

Схема ответа:
{
  "classification": "client|technical|newsletter|personal|ignored|unknown",
  "project_id": integer or null,
  "confidence": float 0..1,
  "event_type": "client_question|client_request|client_reported_bug|client_approved|client_rejected|task_created|task_updated|task_completed|technical_notification|information_only",
  "summary": non-empty string,
  "requires_manager": boolean,
  "suggested_action": "no_action|review|reply|follow_up",
  "draft_reply": string or null (обязателен если suggested_action=reply),
  "reason": string or null,
  "agent_facts": object or null — опционально для памяти диалога:
    {
      "topic": string|null,
      "open_questions": string[],
      "commitments": string[],
      "waiting_for": string|null,
      "key_points": string[]
    }
}

Правила:
- Не выдумывай проекты. project_id только из списка кандидатов: {$candidateIds}. Иначе null.
- Кандидаты с именами: {$candidateProjects}.
- Если у письма нет project_id, но есть кандидаты — выбери наиболее вероятный или null при сомнении.
- Не предлагай отправку писем самостоятельно; только draft при необходимости.
- classification — рекомендация.
- summary: одно короткое предложение о сути (кто + что произошло). Без преамбулы «Задача обновлена» / «Клиент написал», без полного названия тикета и номера Redmine/Jira, если они уже в теме. Цель — быстро прочитать в списке событий.
- message.body — последний ответ в текущем письме (без цитат). suggested_action и «что произошло сейчас» опирайся на него.
- message.id — текущее письмо. Не подменяй его другим письмом из conversation_messages / recent_events (особенно более поздним — их в контексте нет).
- conversation_agent_state — сжатая память диалога (тема, open_questions, waiting_for). Используй как основной фон проблемы.
- conversation_messages — более ранние письма; у них часто уже есть ai_summary / agent_facts — предпочитай их полному body.
- Правило баланса: (1) событие = реакция на message.body; (2) описание проблемы дополняй из conversation_agent_state и истории; (3) не приписывай клиенту новый запрос, которого нет ни в message.body, ни в явной отсылке к истории.
- message.thread_excerpt — обрезанная цитата внутри текущего письма; фон, не отдельное событие.
- Не выдумывай факты (цифры, сроки, ВМ, перенос), которых нет ни в message.body, ни в conversation_agent_state / conversation_messages / thread_excerpt.
- agent_facts заполняй по возможности: короткие факты для следующих писем (open_questions, waiting_for, commitments).
- Если клиент обещает прислать правки/комментарии/материалы к сроку («в выходные напишу») — requires_manager=true, suggested_action=follow_up или review (не information_only / no_action).
- Пиши summary и draft_reply на языке переписки (обычно русский).{$redmineRule}{$modeRule}{$correctionsRule}
PROMPT;

        return $prompt.\App\Support\MailRoutingGuidance::hermesClassificationBlock();
    }

    private function userPrompt(MessageContext $context): string
    {
        return "Контекст для анализа:\n".json_encode(
            $context->toPromptArray(),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }
}
