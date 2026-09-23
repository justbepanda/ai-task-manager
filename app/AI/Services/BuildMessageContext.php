<?php

namespace App\AI\Services;

use App\AI\DTO\MessageContext;
use App\Domain\Projects\RedmineSubjectProjectExtractor;
use App\Enums\MessageClassification;
use App\Models\AnalysisCorrection;
use App\Models\Event;
use App\Models\Message;
use App\Models\Project;
use App\Support\EmailLatestReplyExtractor;

class BuildMessageContext
{
    public function __construct(
        private readonly RedmineSubjectProjectExtractor $redmineSubjectExtractor,
        private readonly EmailLatestReplyExtractor $replyExtractor = new EmailLatestReplyExtractor,
    ) {}

    public function handle(Message $message): MessageContext
    {
        $message->loadMissing(['conversation', 'project.instruction', 'analysisCorrections', 'projectTask']);

        $conversationLimit = (int) config('ai.context.conversation_messages_limit', 10);
        $eventsLimit = (int) config('ai.context.recent_events_limit', 10);
        $correctionsLimit = (int) config('ai.context.corrections_limit', 15);
        $latestBodyChars = (int) config('ai.context.latest_body_chars', 4000);
        $quotedExcerptChars = (int) config('ai.context.thread_excerpt_chars', 800);
        $conversationBodyChars = (int) config('ai.context.conversation_body_chars', 600);

        $currentSplit = $this->replyExtractor->split(
            (string) ($message->body ?? ''),
            $latestBodyChars,
            $quotedExcerptChars,
        );

        $conversationMessages = [];
        if ($message->conversation_id !== null) {
            $conversationQuery = Message::query()
                ->where('conversation_id', $message->conversation_id)
                ->whereKeyNot($message->id);

            // Never include later thread messages — they leak into summary/reason (e.g. #187 into #130).
            if ($message->received_at !== null) {
                $conversationQuery->where(function ($q) use ($message): void {
                    $q->where('received_at', '<', $message->received_at)
                        ->orWhere(function ($q2) use ($message): void {
                            $q2->where('received_at', '=', $message->received_at)
                                ->where('id', '<', $message->id);
                        });
                });
            } else {
                $conversationQuery->where('id', '<', $message->id);
            }

            $conversationMessages = $conversationQuery
                ->orderByDesc('received_at')
                ->limit($conversationLimit)
                ->get(['id', 'sender', 'subject', 'body', 'received_at', 'classification', 'ai_summary', 'agent_facts'])
                ->sortBy('received_at')
                ->values()
                ->map(function (Message $m) use ($conversationBodyChars): array {
                    $facts = is_array($m->agent_facts) ? $m->agent_facts : null;
                    $row = [
                        'id' => $m->id,
                        'sender' => $m->sender,
                        'subject' => $m->subject,
                        'received_at' => $m->received_at?->toIso8601String(),
                        'classification' => $m->classification?->value,
                        'ai_summary' => $m->ai_summary,
                        'agent_facts' => $facts,
                    ];

                    // Prefer compact facts; keep a short latest body as fallback / detail.
                    if ($facts === null || ($facts['summary'] ?? null) === null) {
                        $row['body'] = $this->replyExtractor->latest((string) ($m->body ?? ''), $conversationBodyChars);
                    } else {
                        $row['body'] = $this->replyExtractor->latest(
                            (string) ($m->body ?? ''),
                            min(240, $conversationBodyChars),
                        );
                    }

                    return $row;
                })
                ->all();
        }

        $conversationAgentState = null;
        if ($message->conversation !== null && is_array($message->conversation->agent_state)) {
            $conversationAgentState = $message->conversation->agent_state;
        }

        $project = null;
        $instruction = null;
        if ($message->project !== null) {
            $project = [
                'id' => $message->project->id,
                'name' => $message->project->name,
                'description' => $message->project->description,
                'status' => $message->project->status->value,
            ];
            $instruction = $message->project->instruction?->body;
        }

        $recentEvents = [];
        if ($message->project_id !== null) {
            $eventsQuery = Event::query()
                ->where('project_id', $message->project_id)
                ->where(function ($q) use ($message): void {
                    $q->whereNull('message_id')
                        ->orWhere('message_id', '!=', $message->id);
                });

            // Prefer events from earlier mail only (avoid later-thread leakage).
            if ($message->received_at !== null) {
                $eventsQuery->where(function ($q) use ($message): void {
                    $q->whereDoesntHave('message')
                        ->orWhereHas('message', function ($mq) use ($message): void {
                            $mq->where('received_at', '<', $message->received_at);
                        });
                });
            }

            $recentEvents = $eventsQuery
                ->orderByDesc('id')
                ->limit($eventsLimit)
                ->get(['id', 'type', 'summary', 'confidence', 'created_at'])
                ->map(static fn (Event $event): array => [
                    'id' => $event->id,
                    'type' => $event->type->value,
                    'summary' => $event->summary,
                    'confidence' => $event->confidence !== null ? (float) $event->confidence : null,
                    'created_at' => $event->created_at?->toIso8601String(),
                ])
                ->all();
        }

        $candidateProjectIds = $this->resolveCandidateProjectIds($message);
        $candidateProjects = $this->loadCandidateProjects($candidateProjectIds);
        $matchingHints = $this->buildMatchingHints($message);
        $analysisMode = $message->classification === MessageClassification::Technical
            ? 'technical_summary'
            : 'full';

        return new MessageContext(
            message: [
                'id' => $message->id,
                'sender' => $message->sender,
                'recipients' => $message->recipients,
                'cc' => $message->cc,
                'subject' => $message->subject,
                // Latest reply only — quoted history is separate to avoid wrong event/reason.
                'body' => $currentSplit['latest'],
                'thread_excerpt' => $currentSplit['quoted_excerpt'],
                'received_at' => $message->received_at?->toIso8601String(),
                'classification' => $message->classification?->value,
                'project_id' => $message->project_id,
                'project_task_id' => $message->project_task_id,
                'project_task' => $message->projectTask !== null ? [
                    'id' => $message->projectTask->id,
                    'source' => $message->projectTask->source->value,
                    'external_key' => $message->projectTask->external_key,
                    'title' => $message->projectTask->title,
                    'status' => $message->projectTask->status->value,
                ] : null,
                'agent_facts' => is_array($message->agent_facts) ? $message->agent_facts : null,
            ],
            conversationMessages: $conversationMessages,
            project: $project,
            instruction: $instruction,
            recentEvents: $recentEvents,
            candidateProjectIds: $candidateProjectIds,
            candidateProjects: $candidateProjects,
            matchingHints: $matchingHints,
            analysisCorrections: $this->mapCorrections($message->analysisCorrections),
            recentProjectCorrections: $this->loadRecentProjectCorrections($message, $correctionsLimit),
            conversationAgentState: $conversationAgentState,
            analysisMode: $analysisMode,
        );
    }

    /**
     * @return list<int>
     */
    private function resolveCandidateProjectIds(Message $message): array
    {
        $ids = [];

        if ($message->project_id !== null) {
            $ids[] = (int) $message->project_id;
        }

        $metadata = is_array($message->metadata) ? $message->metadata : [];
        $candidates = $metadata['project_match']['candidates'] ?? [];
        if (is_array($candidates)) {
            foreach ($candidates as $candidate) {
                if (is_array($candidate) && isset($candidate['project_id'])) {
                    $ids[] = (int) $candidate['project_id'];
                }
            }
        }

        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            return [];
        }

        $existing = Project::query()->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->all();

        return array_values(array_intersect($ids, $existing));
    }

    /**
     * @param  list<int>  $ids
     * @return list<array{id: int, name: string}>
     */
    private function loadCandidateProjects(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Project::query()
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(static fn (Project $project): array => [
                'id' => (int) $project->id,
                'name' => (string) $project->name,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, AnalysisCorrection>|iterable<AnalysisCorrection>  $corrections
     * @return list<array{field: string, ai_value: string|null, manager_value: string|null}>
     */
    private function mapCorrections(iterable $corrections): array
    {
        $rows = [];
        foreach ($corrections as $correction) {
            if (! $correction instanceof AnalysisCorrection) {
                continue;
            }
            $rows[] = [
                'field' => (string) $correction->field,
                'ai_value' => $correction->ai_value !== null ? (string) $correction->ai_value : null,
                'manager_value' => $correction->manager_value !== null ? (string) $correction->manager_value : null,
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{field: string, ai_value: string|null, manager_value: string|null}>
     */
    private function loadRecentProjectCorrections(Message $message, int $limit): array
    {
        if ($message->project_id === null || $limit <= 0) {
            return [];
        }

        $corrections = AnalysisCorrection::query()
            ->whereHas('message', function ($q) use ($message): void {
                $q->where('project_id', $message->project_id)
                    ->whereKeyNot($message->id);
            })
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return $this->mapCorrections($corrections);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildMatchingHints(Message $message): ?array
    {
        if (! $this->redmineSubjectExtractor->appliesTo($message)) {
            return null;
        }

        $subject = (string) ($message->subject ?? '');
        $names = $this->redmineSubjectExtractor->extractNameCandidates($subject);
        if ($names === []) {
            return [
                'source' => 'redmine_subject',
                'rule' => 'Для писем от Redmine название проекта бери в первую очередь из темы (текст в [скобках] до « - Tracker #id»). Не выдумывай проект вне candidate_projects.',
                'subject_project_names' => [],
            ];
        }

        return [
            'source' => 'redmine_subject',
            'rule' => 'Для писем от Redmine название проекта бери в первую очередь из темы (текст в [скобках] до « - Tracker #id»). Сопоставь с candidate_projects по имени. Не выдумывай проект.',
            'subject_project_names' => $names,
        ];
    }
}
