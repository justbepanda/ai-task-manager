<?php

namespace App\AI\Services;

use App\AI\DTO\MessageContext;
use App\Domain\Projects\RedmineSubjectProjectExtractor;
use App\Enums\MessageClassification;
use App\Models\Event;
use App\Models\Message;
use App\Models\Project;

class BuildMessageContext
{
    public function __construct(
        private readonly RedmineSubjectProjectExtractor $redmineSubjectExtractor,
    ) {}

    public function handle(Message $message): MessageContext
    {
        $message->loadMissing(['conversation', 'project.instruction']);

        $conversationLimit = (int) config('ai.context.conversation_messages_limit', 10);
        $eventsLimit = (int) config('ai.context.recent_events_limit', 10);

        $conversationMessages = [];
        if ($message->conversation_id !== null) {
            $conversationMessages = Message::query()
                ->where('conversation_id', $message->conversation_id)
                ->whereKeyNot($message->id)
                ->orderByDesc('received_at')
                ->limit($conversationLimit)
                ->get(['id', 'sender', 'subject', 'body', 'received_at', 'classification'])
                ->sortBy('received_at')
                ->values()
                ->map(static fn (Message $m): array => [
                    'id' => $m->id,
                    'sender' => $m->sender,
                    'subject' => $m->subject,
                    'body' => $m->body,
                    'received_at' => $m->received_at?->toIso8601String(),
                    'classification' => $m->classification?->value,
                ])
                ->all();
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
            $recentEvents = Event::query()
                ->where('project_id', $message->project_id)
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

        return new MessageContext(
            message: [
                'id' => $message->id,
                'sender' => $message->sender,
                'recipients' => $message->recipients,
                'cc' => $message->cc,
                'subject' => $message->subject,
                'body' => $message->body,
                'received_at' => $message->received_at?->toIso8601String(),
                'classification' => $message->classification?->value,
                'project_id' => $message->project_id,
            ],
            conversationMessages: $conversationMessages,
            project: $project,
            instruction: $instruction,
            recentEvents: $recentEvents,
            candidateProjectIds: $candidateProjectIds,
            candidateProjects: $candidateProjects,
            matchingHints: $matchingHints,
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

        if ($ids === [] && $message->classification === MessageClassification::Unknown) {
            // Do not invent — empty candidates means AI must return null project_id.
            return [];
        }

        // Ensure candidates still exist.
        if ($ids !== []) {
            $existing = Project::query()->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->all();

            return array_values(array_intersect($ids, $existing));
        }

        return $ids;
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
