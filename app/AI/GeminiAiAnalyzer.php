<?php

namespace App\AI;

use App\AI\Contracts\AiAnalyzer;
use App\AI\DTO\MessageContext;
use App\AI\Exceptions\AnalysisException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class GeminiAiAnalyzer implements AiAnalyzer
{
    /**
     * @return array<string, mixed>
     */
    public function analyze(MessageContext $context): array
    {
        $apiKey = config('ai.gemini.api_key');
        $model = config('ai.gemini.model', 'gemini-3.6-flash');
        $baseUrl = rtrim((string) config('ai.gemini.base_url'), '/');
        $timeout = (int) config('ai.gemini.timeout', 30);

        if (! is_string($apiKey) || $apiKey === '') {
            throw new AnalysisException('Gemini API key is not configured', 'config_error');
        }

        $url = "{$baseUrl}/models/{$model}:generateContent";

        try {
            $response = Http::timeout($timeout)
                ->withHeaders([
                    'x-goog-api-key' => $apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->post($url, [
                    'system_instruction' => [
                        'parts' => [
                            ['text' => $this->systemPrompt($context)],
                        ],
                    ],
                    'contents' => [
                        [
                            'role' => 'user',
                            'parts' => [
                                ['text' => $this->userPrompt($context)],
                            ],
                        ],
                    ],
                    'generationConfig' => [
                        'temperature' => 0.2,
                        'responseMimeType' => 'application/json',
                    ],
                ]);
        } catch (ConnectionException $e) {
            throw new AnalysisException('Gemini API connection failed', 'http_error', $e);
        } catch (Throwable $e) {
            throw new AnalysisException('Gemini API request failed', 'http_error', $e);
        }

        if ($response->failed()) {
            Log::warning('Gemini API HTTP error', [
                'status' => $response->status(),
            ]);

            throw new AnalysisException(
                'Gemini API returned HTTP '.$response->status(),
                'http_error',
            );
        }

        $text = data_get($response->json(), 'candidates.0.content.parts.0.text');
        if (! is_string($text) || trim($text) === '') {
            throw new AnalysisException('Gemini API returned empty content', 'parse_error');
        }

        return $this->parseJsonPayload($text);
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
            $redmineRule = <<<'RULE'

- Письмо от Redmine: название проекта определяй В ПЕРВУЮ ОЧЕРЕДЬ по теме. В [квадратных скобках] до « - Tracker #номер» — имя/алиасы проекта (через запятую). Пример: «[Tekta, Текта, … - Bug #1]» → Tekta; «[RBI сайт 2025 - New #99143]» → RBI сайт 2025. Сопоставь с candidate_projects по имени. Извлечённые из темы имена: 
RULE;
            $redmineRule .= $namesList.'.';
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
- Не предлагай отправку писем самостоятельно; только draft при необходимости.
- classification — рекомендация.
- Пиши summary и draft_reply на языке переписки (обычно русский).{$redmineRule}
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
