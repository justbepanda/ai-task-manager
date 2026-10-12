<?php

declare(strict_types=1);

namespace App\Domain\Projects;

use App\AI\Exceptions\AnalysisException;
use App\Domain\Conversations\PropagateProjectToConversationMessages;
use App\Domain\Metrics\HypothesisMetricsRecorder;
use App\Enums\MessageClassification;
use App\Enums\MessageProcessingStatus;
use App\Enums\ProjectStatus;
use App\Hermes\Contracts\LlmProvider;
use App\Models\AppSetting;
use App\Models\Message;
use App\Models\ProcessingLog;
use App\Models\Project;
use App\Support\MailRoutingGuidance;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Optionally assign (or create) a project via LLM for a single unmatched message.
 * Runs only when AppSetting::auto_assign_projects_llm is on and confidence ≥ threshold.
 * Deterministic Redmine auto-create must run first (caller responsibility).
 */
class AutoAssignProjectViaLlm
{
    public function __construct(
        private readonly LlmProvider $llm,
        private readonly ProjectService $projects,
        private readonly MergeProjects $mergeProjects,
        private readonly HypothesisMetricsRecorder $metrics,
        private readonly PropagateProjectToConversationMessages $propagateProject,
    ) {}

    /**
     * @return array{message: Message, project: Project, created: bool, confidence: float}|null
     */
    public function tryAssign(Message $message): ?array
    {
        if ($message->project_id !== null) {
            return null;
        }

        if ($message->classification === MessageClassification::Ignored) {
            return null;
        }

        $settings = AppSetting::current();
        if (! $settings->auto_assign_projects_llm) {
            return null;
        }

        $threshold = (float) $settings->auto_assign_llm_confidence_threshold;

        $projects = Project::query()
            ->where('status', ProjectStatus::Active)
            ->with('matchingRule')
            ->orderBy('name')
            ->get();

        $bodyChars = (int) config('ai.assign_suggest.body_chars', 400);

        try {
            $payload = $this->llm->completeJson(
                $this->systemPrompt(),
                $this->userPrompt($message, $projects, $bodyChars),
            );
            $decision = $this->parseDecision($payload, $projects, $threshold);
        } catch (AnalysisException $e) {
            if ($e->isRetryable()) {
                ProcessingLog::query()->create([
                    'message_id' => $message->id,
                    'step' => 'project_auto_assigned_llm',
                    'status' => 'deferred',
                    'details' => [
                        'error' => $e->getMessage(),
                        'error_type' => $e->errorType,
                        'retry' => true,
                        'ai_model' => $this->llm->lastModel()
                            ?: (string) config('ai.gemini.model', 'unknown'),
                    ],
                ]);

                Log::warning('AutoAssignProjectViaLlm: deferred for queue retry', [
                    'message_id' => $message->id,
                    'error_type' => $e->errorType,
                ]);

                throw $e;
            }

            ProcessingLog::query()->create([
                'message_id' => $message->id,
                'step' => 'project_auto_assigned_llm',
                'status' => 'failed',
                'details' => [
                    'error' => $e->getMessage(),
                    'error_type' => $e->errorType,
                    'ai_model' => $this->llm->lastModel()
                        ?: (string) config('ai.gemini.model', 'unknown'),
                ],
            ]);

            Log::warning('AutoAssignProjectViaLlm: LLM call failed', [
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        } catch (Throwable $e) {
            ProcessingLog::query()->create([
                'message_id' => $message->id,
                'step' => 'project_auto_assigned_llm',
                'status' => 'failed',
                'details' => [
                    'error' => $e->getMessage(),
                    'ai_model' => $this->llm->lastModel()
                        ?: (string) config('ai.gemini.model', 'unknown'),
                ],
            ]);

            Log::warning('AutoAssignProjectViaLlm: LLM call failed', [
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ($decision === null) {
            ProcessingLog::query()->create([
                'message_id' => $message->id,
                'step' => 'project_auto_assigned_llm',
                'status' => 'skipped',
                'details' => [
                    'reason' => 'unmatched_or_below_threshold',
                    'threshold' => $threshold,
                    'payload' => $payload,
                ],
            ]);

            return null;
        }

        $created = false;
        $project = $decision['project'];

        if ($project === null) {
            $needles = array_values(array_filter(array_merge(
                [$decision['proposed_name'] ?? ''],
                $decision['aliases'],
            )));
            $existing = $this->mergeProjects->findByFoldedNames($needles);
            if ($existing !== null) {
                $project = $existing;
            } else {
                $project = $this->projects->create([
                    'name' => $decision['proposed_name'],
                    'status' => ProjectStatus::Active,
                    'description' => 'Автосоздан через LLM-распределение',
                    'keywords' => $decision['aliases'] === []
                        ? null
                        : implode("\n", $decision['aliases']),
                ]);
                $created = true;
            }
        }

        $message = $this->attachMessage($message, $project, $decision, $created, $threshold);

        ProcessingLog::query()->create([
            'message_id' => $message->id,
            'step' => 'project_auto_assigned_llm',
            'status' => 'ok',
            'details' => [
                'project_id' => $project->id,
                'created' => $created,
                'confidence' => $decision['confidence'],
                'threshold' => $threshold,
                'reason' => $decision['reason'],
                'proposed_name' => $decision['proposed_name'],
            ],
        ]);

        $this->metrics->record(
            $created ? 'project_auto_created_llm' : 'project_auto_assigned_llm',
            [
                'project_id' => $project->id,
                'confidence' => $decision['confidence'],
                'created' => $created,
            ],
            $message,
            projectId: (int) $project->id,
        );

        return [
            'message' => $message,
            'project' => $project,
            'created' => $created,
            'confidence' => $decision['confidence'],
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Project>  $projects
     * @return array{
     *     project: ?Project,
     *     proposed_name: ?string,
     *     aliases: list<string>,
     *     confidence: float,
     *     reason: string
     * }|null
     */
    private function parseDecision(array $payload, $projects, float $threshold): ?array
    {
        if (($payload['unmatched'] ?? false) === true) {
            return null;
        }

        $confidence = is_numeric($payload['confidence'] ?? null)
            ? max(0.0, min(1.0, (float) $payload['confidence']))
            : null;

        if ($confidence === null || $confidence < $threshold) {
            return null;
        }

        $reason = isset($payload['reason']) && is_string($payload['reason']) && trim($payload['reason']) !== ''
            ? trim($payload['reason'])
            : 'llm_auto_assign';

        $projectId = array_key_exists('project_id', $payload) && $payload['project_id'] !== null
            ? (is_numeric($payload['project_id']) ? (int) $payload['project_id'] : null)
            : null;

        if ($projectId !== null) {
            $project = $projects->firstWhere('id', $projectId);
            if ($project === null) {
                Log::info('AutoAssignProjectViaLlm ignored unknown project_id', [
                    'project_id' => $projectId,
                ]);

                return null;
            }

            return [
                'project' => $project,
                'proposed_name' => null,
                'aliases' => [],
                'confidence' => $confidence,
                'reason' => $reason,
            ];
        }

        $proposedName = isset($payload['proposed_name']) && is_string($payload['proposed_name'])
            ? trim($payload['proposed_name'])
            : '';

        if ($proposedName === '') {
            return null;
        }

        $aliases = [];
        foreach ($payload['proposed_aliases'] ?? [] as $alias) {
            if (is_string($alias) && trim($alias) !== '') {
                $aliases[] = trim($alias);
            }
        }

        return [
            'project' => null,
            'proposed_name' => $proposedName,
            'aliases' => $aliases,
            'confidence' => $confidence,
            'reason' => $reason,
        ];
    }

    /**
     * @param  array{
     *     project: ?Project,
     *     proposed_name: ?string,
     *     aliases: list<string>,
     *     confidence: float,
     *     reason: string
     * }  $decision
     */
    private function attachMessage(
        Message $message,
        Project $project,
        array $decision,
        bool $created,
        float $threshold,
    ): Message {
        $metadata = is_array($message->metadata) ? $message->metadata : [];
        $match = is_array($metadata['project_match'] ?? null) ? $metadata['project_match'] : [];
        $reasons = is_array($match['reasons'] ?? null) ? $match['reasons'] : [];
        $reasons[] = 'llm_auto_assign:'.$decision['reason'];

        $metadata['project_match'] = array_merge($match, [
            'project_id' => $project->id,
            'ambiguous' => false,
            'reasons' => array_values($reasons),
            'auto_created' => $created,
            'llm_auto_assigned' => true,
            'llm_confidence' => $decision['confidence'],
            'llm_threshold' => $threshold,
        ]);

        $status = $message->processing_status;
        if (in_array($message->classification, [
            MessageClassification::Client,
            MessageClassification::SiteLead,
        ], true) && $status === MessageProcessingStatus::NeedsManual) {
            $status = MessageProcessingStatus::Classified;
        }

        $message->fill([
            'project_id' => $project->id,
            'processing_status' => $status,
            'metadata' => $metadata,
        ]);
        $message->save();

        if ($message->conversation && $message->conversation->project_id === null) {
            $message->conversation->update(['project_id' => $project->id]);
        }

        $fresh = $message->fresh(['conversation', 'project']);
        if ($fresh !== null) {
            $this->propagateProject->handle($fresh, (int) $project->id);
            $fresh = $fresh->fresh(['conversation', 'project']) ?? $fresh;
        }

        return $fresh ?? $message;
    }

    private function systemPrompt(): string
    {
        $base = <<<'PROMPT'
Ты — помощник менеджера проектов. Определи, к какому проекту относится одно входящее письмо.
Верни ТОЛЬКО валидный JSON без markdown.

Схема:
{
  "project_id": integer or null,
  "proposed_name": string or null,
  "proposed_aliases": [string],
  "confidence": float 0..1,
  "reason": string,
  "unmatched": boolean
}

Правила:
- project_id только из списка projects. Иначе null.
- Если проекта нет, но письмо явно относится к новому — proposed_name (+ aliases), project_id=null, unmatched=false.
- Если уверенности нет — unmatched=true, project_id=null, proposed_name=null.
- Не привязывай рассылки/личное/шум к проектам без явной связи — unmatched=true.
- Для Redmine: имя из темы [Name - Tracker #id].
- reason — кратко, на русском.
- confidence — твоя оценка уверенности 0..1.
PROMPT;

        return $base."\n".MailRoutingGuidance::projectAssignBlock();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Project>  $projects
     */
    private function userPrompt(Message $message, $projects, int $bodyChars): string
    {
        $projectRows = $projects->map(static function (Project $project): array {
            $rule = $project->matchingRule;

            return [
                'id' => (int) $project->id,
                'name' => (string) $project->name,
                'client_emails' => $rule?->client_emails ?? [],
                'domains' => $rule?->domains ?? [],
                'keywords' => array_slice($rule?->keywords ?? [], 0, 12),
                'technical_sources' => $rule?->technical_sources ?? [],
            ];
        })->values()->all();

        $body = (string) ($message->body ?? '');
        if (mb_strlen($body) > $bodyChars) {
            $body = mb_substr($body, 0, $bodyChars).'…';
        }

        return "Контекст для распределения одного письма:\n".json_encode(
            [
                'projects' => $projectRows,
                'message' => [
                    'id' => (int) $message->id,
                    'sender' => (string) ($message->sender ?? ''),
                    'subject' => (string) ($message->subject ?? ''),
                    'received_at' => optional($message->received_at)?->toIso8601String(),
                    'classification' => $message->classification?->value ?? $message->classification,
                    'body' => $body,
                ],
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }
}
