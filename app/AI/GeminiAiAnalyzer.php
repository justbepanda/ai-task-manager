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

        foreach ($models as $modelIndex => $model) {
            $url = "{$baseUrl}/models/{$model}:generateContent";

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

                    if ($modelIndex < count($models) - 1) {
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

                $retryable = in_array($lastStatus, self::RETRYABLE_STATUSES, true);
                if ($retryable && $attempt < $maxAttempts) {
                    $this->sleepBeforeRetry($baseDelayMs, $attempt);

                    continue;
                }

                if ($retryable && $modelIndex < count($models) - 1) {
                    // Try next fallback model.
                    continue 2;
                }

                throw new AnalysisException(
                    $this->userFacingHttpError($lastStatus),
                    'http_error',
                );
            }
        }

        throw new AnalysisException(
            $this->userFacingHttpError($lastStatus),
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

    private function userFacingHttpError(?int $status): string
    {
        if ($status === 429) {
            return 'Gemini перегружен (лимит запросов). Подождите минуту и нажмите «Запросить» снова.';
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
                .'Дай короткий summary, event_type обычно technical_notification или task_updated/task_created. '
                .'suggested_action обычно no_action, draft_reply=null, requires_manager=false — '
                .'кроме: (1) в теме есть «(Обратная связь)» → requires_manager=true, suggested_action=review; '
                .'(2) явная критичность (падение, блокер) → review.';
        }

        $correctionsRule = '';
        if ($context->analysisCorrections !== [] || $context->recentProjectCorrections !== []) {
            $correctionsRule = "\n- Учти analysis_corrections / recent_project_corrections: менеджер раньше правил AI — "
                .'не повторяй типичные ошибки (проект, классификация, тон draft).';
        }

        return <<<PROMPT
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
  "reason": string or null
}

Правила:
- Не выдумывай проекты. project_id только из списка кандидатов: {$candidateIds}. Иначе null.
- Кандидаты с именами: {$candidateProjects}.
- Если у письма нет project_id, но есть кандидаты — выбери наиболее вероятный или null при сомнении.
- Не предлагай отправку писем самостоятельно; только draft при необходимости.
- classification — рекомендация.
- message.body — это ТОЛЬКО последний ответ в письме (без цитат). summary, reason, suggested_action и draft_reply обязаны относиться к нему.
- message.id — текущее письмо. Не описывай другие письма из conversation_messages / recent_events как текущее событие.
- conversation_messages — только более ранние письма диалога (фон). Если в них другой смысл — игнорируй для summary/reason.
- message.thread_excerpt — цитата внутри текущего письма; не подменяй ею смысл message.body.
- Если message.body короткий (слот времени, «ок», «спасибо») — event/reason/draft только про него, без тем из старой переписки.
- Не добавляй в summary/reason конкретные темы (ВМ, перенос сервера, бюджетные цифры, имена фич), которых нет в message.body — даже если они есть в conversation_messages или recent_events.
- Если клиент обещает прислать правки/комментарии/материалы к сроку («в выходные напишу») — requires_manager=true, suggested_action=follow_up или review (не information_only / no_action).
- Пиши summary и draft_reply на языке переписки (обычно русский).{$redmineRule}{$modeRule}{$correctionsRule}
PROMPT;
    }

    private function userPrompt(MessageContext $context): string
    {
        return "Контекст для анализа:\n".json_encode(
            $context->toPromptArray(),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }
}
