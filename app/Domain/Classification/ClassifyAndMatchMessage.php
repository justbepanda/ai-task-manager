<?php

namespace App\Domain\Classification;

use App\Domain\Classification\DTO\ClassificationResult;
use App\Domain\Metrics\HypothesisMetricsRecorder;
use App\Domain\Projects\AutoCreateProjectFromIncomingMail;
use App\Domain\Projects\DTO\MatchResult;
use App\Domain\Projects\ProjectMatcher;
use App\Enums\MessageClassification;
use App\Enums\MessageProcessingStatus;
use App\Jobs\AnalyzeMessageJob;
use App\Models\Message;
use App\Models\ProcessingLog;
use Illuminate\Support\Facades\DB;

class ClassifyAndMatchMessage
{
    public function __construct(
        private readonly MessageClassifier $classifier,
        private readonly ProjectMatcher $matcher,
        private readonly AutoCreateProjectFromIncomingMail $autoCreateProject,
        private readonly HypothesisMetricsRecorder $metrics,
    ) {}

    /**
     * @return array{
     *     message: Message,
     *     classification: ClassificationResult,
     *     match: MatchResult,
     *     dispatch_analyze: bool
     * }
     */
    public function handle(Message $message): array
    {
        return DB::transaction(function () use ($message): array {
            $message = Message::query()->lockForUpdate()->findOrFail($message->id);
            $message->load('conversation');

            $classification = $this->classifier->classify($message);
            $match = $this->matcher->match($message);

            // Ignored mail never needs a project (blacklist / system noise).
            $projectId = $classification->classification === MessageClassification::Ignored
                ? null
                : $match->projectId;

            $autoCreated = false;
            if ($projectId === null && $classification->classification !== MessageClassification::Ignored) {
                $auto = $this->autoCreateProject->tryCreate(
                    $message,
                    $classification->classification,
                    $match->projectId,
                    $match->ambiguous,
                );

                if ($auto !== null) {
                    $projectId = (int) $auto['project']->id;
                    $autoCreated = $auto['created'];
                    $reason = $autoCreated
                        ? 'auto_create_project:'.$auto['project']->name
                        : 'auto_attach_project:'.$auto['project']->name;
                    $match = new MatchResult(
                        projectId: $projectId,
                        ambiguous: false,
                        candidates: $match->candidates,
                        reasons: array_values(array_merge($match->reasons, [$reason])),
                    );
                }
            }

            $status = $this->resolveProcessingStatus($classification, $match, $projectId);

            $metadata = is_array($message->metadata) ? $message->metadata : [];
            $metadata['classification_confidence'] = $classification->confidence;
            $metadata['classification_reasons'] = $classification->reasons;
            $metadata['project_match'] = [
                'project_id' => $projectId,
                'ambiguous' => $match->ambiguous,
                'reasons' => $match->reasons,
                'auto_created' => $autoCreated,
                'candidates' => array_map(
                    static fn ($c) => [
                        'project_id' => $c->projectId,
                        'score' => $c->score,
                        'reasons' => $c->reasons,
                    ],
                    $match->candidates,
                ),
            ];

            $message->fill([
                'classification' => $classification->classification,
                'project_id' => $projectId,
                'processing_status' => $status,
                'metadata' => $metadata,
                'processing_error' => null,
            ]);
            $message->save();

            if ($projectId !== null && $message->conversation && $message->conversation->project_id === null) {
                $message->conversation->update(['project_id' => $projectId]);
            }

            ProcessingLog::query()->create([
                'message_id' => $message->id,
                'step' => 'classified',
                'status' => 'ok',
                'details' => [
                    'classification' => $classification->classification->value,
                    'confidence' => $classification->confidence,
                    'reasons' => $classification->reasons,
                ],
            ]);

            ProcessingLog::query()->create([
                'message_id' => $message->id,
                'step' => 'project_matched',
                'status' => $match->ambiguous ? 'ambiguous' : ($projectId !== null ? 'ok' : 'unmatched'),
                'details' => [
                    'project_id' => $projectId,
                    'ambiguous' => $match->ambiguous,
                    'reasons' => $match->reasons,
                    'auto_created' => $autoCreated,
                    'candidates' => array_map(
                        static fn ($c) => [
                            'project_id' => $c->projectId,
                            'score' => $c->score,
                            'reasons' => $c->reasons,
                        ],
                        $match->candidates,
                    ),
                    'processing_status' => $status->value,
                ],
            ]);

            $this->metrics->record(
                'mail_classified_'.$classification->classification->value,
                ['reasons' => $classification->reasons],
                $message,
                projectId: $projectId,
            );

            if ($match->ambiguous) {
                $this->metrics->record('project_ambiguous', [
                    'candidates' => count($match->candidates),
                ], $message);
            } elseif ($projectId !== null) {
                $this->metrics->record('project_auto_matched', [
                    'project_id' => $projectId,
                    'auto_created' => $autoCreated,
                ], $message, projectId: $projectId);
            } else {
                $this->metrics->record('project_unmatched', [
                    'classification' => $classification->classification->value,
                ], $message);
            }

            $needsLlm = $this->needsLlm($classification, $projectId, $match);

            return [
                'message' => $message->fresh(['conversation', 'processingLogs']),
                'classification' => $classification,
                'match' => $match,
                'needs_llm' => $needsLlm,
                /** @deprecated use needs_llm */
                'dispatch_analyze' => $needsLlm,
            ];
        });
    }

    private function resolveProcessingStatus(
        ClassificationResult $classification,
        MatchResult $match,
        ?int $projectId,
    ): MessageProcessingStatus {
        $type = $classification->classification;

        if ($type === MessageClassification::Unknown) {
            return MessageProcessingStatus::NeedsManual;
        }

        if ($type === MessageClassification::Client) {
            if ($match->ambiguous || $projectId === null) {
                return MessageProcessingStatus::NeedsManual;
            }

            return MessageProcessingStatus::Classified;
        }

        // technical / newsletter / personal / ignored — no project required
        if ($match->ambiguous && $type === MessageClassification::Technical) {
            // Keep classified; project left null, no invention.
            return MessageProcessingStatus::Classified;
        }

        return MessageProcessingStatus::Classified;
    }

    private function needsLlm(ClassificationResult $classification, ?int $projectId, MatchResult $match): bool
    {
        $hasCandidates = $match->candidates !== [];

        return match ($classification->classification) {
            // Full analysis when project known, or pick among candidates when ambiguous/unmatched.
            MessageClassification::Client,
            MessageClassification::Unknown => $projectId !== null || $hasCandidates,
            // Light summary for project technical mail (e.g. Redmine).
            MessageClassification::Technical => $projectId !== null,
            MessageClassification::Newsletter,
            MessageClassification::Personal,
            MessageClassification::Ignored => false,
        };
    }

    /**
     * @deprecated Prefer {@see \App\Hermes\ProcessIncomingMessage}
     */
    public function dispatchAnalyzeIfNeeded(Message $message, bool $shouldDispatch): void
    {
        if ($shouldDispatch) {
            AnalyzeMessageJob::dispatch($message->id);
        }
    }
}
