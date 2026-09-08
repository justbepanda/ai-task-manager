<?php

namespace App\Domain\Classification;

use App\Domain\Classification\DTO\ClassificationResult;
use App\Domain\Metrics\HypothesisMetricsRecorder;
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
            $status = $this->resolveProcessingStatus($classification, $match);

            $metadata = is_array($message->metadata) ? $message->metadata : [];
            $metadata['classification_confidence'] = $classification->confidence;
            $metadata['classification_reasons'] = $classification->reasons;
            $metadata['project_match'] = [
                'project_id' => $projectId,
                'ambiguous' => $match->ambiguous,
                'reasons' => $match->reasons,
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
                ], $message, projectId: $projectId);
            } else {
                $this->metrics->record('project_unmatched', [
                    'classification' => $classification->classification->value,
                ], $message);
            }

            $dispatchAnalyze = $this->shouldDispatchAnalyze($classification, $projectId);

            return [
                'message' => $message->fresh(['conversation', 'processingLogs']),
                'classification' => $classification,
                'match' => $match,
                'dispatch_analyze' => $dispatchAnalyze,
            ];
        });
    }

    private function resolveProcessingStatus(ClassificationResult $classification, MatchResult $match): MessageProcessingStatus
    {
        $type = $classification->classification;

        if ($type === MessageClassification::Unknown) {
            return MessageProcessingStatus::NeedsManual;
        }

        if ($type === MessageClassification::Client) {
            if ($match->ambiguous || $match->projectId === null) {
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

    private function shouldDispatchAnalyze(ClassificationResult $classification, ?int $projectId): bool
    {
        return match ($classification->classification) {
            MessageClassification::Client => $projectId !== null,
            MessageClassification::Unknown => $projectId !== null,
            MessageClassification::Technical,
            MessageClassification::Newsletter,
            MessageClassification::Personal,
            MessageClassification::Ignored => false,
        };
    }

    public function dispatchAnalyzeIfNeeded(Message $message, bool $shouldDispatch): void
    {
        if ($shouldDispatch) {
            AnalyzeMessageJob::dispatch($message->id);
        }
    }
}
