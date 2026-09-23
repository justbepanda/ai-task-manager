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
     * @param  list<array{field: string, ai_value: string|null, manager_value: string|null}>  $analysisCorrections
     * @param  list<array{field: string, ai_value: string|null, manager_value: string|null}>  $recentProjectCorrections
     * @param  array<string, mixed>|null  $conversationAgentState
     * @param  string  $analysisMode  full|technical_summary
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
        public array $analysisCorrections = [],
        public array $recentProjectCorrections = [],
        public ?array $conversationAgentState = null,
        public string $analysisMode = 'full',
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toPromptArray(): array
    {
        $payload = [
            'analysis_mode' => $this->analysisMode,
            'message' => $this->message,
            'conversation_agent_state' => $this->conversationAgentState,
            'conversation_messages' => $this->conversationMessages,
            'project' => $this->project,
            'instruction' => $this->instruction,
            'recent_events' => $this->recentEvents,
            'candidate_project_ids' => $this->candidateProjectIds,
            'candidate_projects' => $this->candidateProjects,
            'analysis_corrections' => $this->analysisCorrections,
            'recent_project_corrections' => $this->recentProjectCorrections,
        ];

        if ($this->matchingHints !== null) {
            $payload['matching_hints'] = $this->matchingHints;
        }

        return $payload;
    }
}
