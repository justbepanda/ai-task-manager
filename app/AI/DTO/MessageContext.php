<?php

namespace App\AI\DTO;

readonly class MessageContext
{
    /**
     * @param  array<string, mixed>  $message
     * @param  list<array<string, mixed>>  $conversationMessages
     * @param  array<string, mixed>|null  $project
     * @param  list<array<string, mixed>>  $recentEvents
     * @param  list<int>  $candidateProjectIds
     * @param  list<array{id: int, name: string}>  $candidateProjects
     * @param  array<string, mixed>|null  $matchingHints
     */
    public function __construct(
        public array $message,
        public array $conversationMessages = [],
        public ?array $project = null,
        public ?string $instruction = null,
        public array $recentEvents = [],
        public array $candidateProjectIds = [],
        public array $candidateProjects = [],
        public ?array $matchingHints = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toPromptArray(): array
    {
        $payload = [
            'message' => $this->message,
            'conversation_messages' => $this->conversationMessages,
            'project' => $this->project,
            'instruction' => $this->instruction,
            'recent_events' => $this->recentEvents,
            'candidate_project_ids' => $this->candidateProjectIds,
            'candidate_projects' => $this->candidateProjects,
        ];

        if ($this->matchingHints !== null) {
            $payload['matching_hints'] = $this->matchingHints;
        }

        return $payload;
    }
}
