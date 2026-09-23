<?php

namespace App\Mail\Services;

use App\Domain\Metrics\HypothesisMetricsRecorder;
use App\Enums\MessageProcessingStatus;
use App\Mail\DTO\NormalizedIncomingMessage;
use App\Mail\Imap\ImapMessageMapper;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageRaw;
use App\Models\ProcessingLog;
use Illuminate\Support\Facades\DB;

class PersistIncomingMessage
{
    public function __construct(
        private readonly ImapMessageMapper $mapper = new ImapMessageMapper,
        private readonly ?HypothesisMetricsRecorder $metrics = null,
    ) {}

    private function metrics(): HypothesisMetricsRecorder
    {
        return $this->metrics ?? app(HypothesisMetricsRecorder::class);
    }

    /**
     * @return array{message: Message, created: bool}
     */
    public function handle(NormalizedIncomingMessage $incoming): array
    {
        if ($incoming->isEmpty()) {
            throw new \InvalidArgumentException(
                'Refusing to persist empty IMAP message '.$incoming->externalId
            );
        }

        $existing = Message::query()
            ->where('source', $incoming->source)
            ->where('external_id', $incoming->externalId)
            ->first();

        if ($existing !== null) {
            $existing->fill([
                'sender' => $incoming->sender,
                'recipients' => $incoming->recipients,
                'cc' => $incoming->cc,
                'subject' => $incoming->subject,
                'body' => $incoming->body,
                'headers' => $incoming->headers,
                'metadata' => $incoming->metadata,
            ]);
            $existing->save();

            if ($incoming->rawHeaders !== null || $incoming->rawBody !== null) {
                MessageRaw::query()->updateOrCreate(
                    ['message_id' => $existing->id],
                    [
                        'raw_headers' => $incoming->rawHeaders,
                        'raw_body' => $incoming->rawBody,
                        'payload' => [
                            'metadata' => $incoming->metadata,
                        ],
                    ],
                );
            }

            return ['message' => $existing->refresh(), 'created' => false];
        }

        return DB::transaction(function () use ($incoming): array {
            $conversation = $this->resolveConversation($incoming);

            $message = Message::query()->create([
                'source' => $incoming->source,
                'external_id' => $incoming->externalId,
                'conversation_id' => $conversation->id,
                'project_id' => null,
                'sender' => $incoming->sender,
                'recipients' => $incoming->recipients,
                'cc' => $incoming->cc,
                'subject' => $incoming->subject,
                'body' => $incoming->body,
                'received_at' => $incoming->receivedAt,
                'headers' => $incoming->headers,
                'metadata' => $incoming->metadata,
                'processing_status' => MessageProcessingStatus::Pending,
            ]);

            if ($incoming->rawHeaders !== null || $incoming->rawBody !== null) {
                MessageRaw::query()->create([
                    'message_id' => $message->id,
                    'raw_headers' => $incoming->rawHeaders,
                    'raw_body' => $incoming->rawBody,
                    'payload' => [
                        'metadata' => $incoming->metadata,
                    ],
                ]);
            }

            ProcessingLog::query()->create([
                'message_id' => $message->id,
                'step' => 'imported',
                'status' => 'ok',
                'details' => [
                    'source' => $incoming->source->value,
                    'external_id' => $incoming->externalId,
                ],
            ]);

            $this->metrics()->record('mail_received', [
                'source' => $incoming->source->value,
            ], $message);

            return ['message' => $message, 'created' => true];
        });
    }

    private function resolveConversation(NormalizedIncomingMessage $incoming): Conversation
    {
        $candidateIds = $this->threadCandidateIds($incoming);

        foreach ($candidateIds as $messageId) {
            $existing = Message::query()
                ->where('source', $incoming->source)
                ->where(function ($query) use ($messageId): void {
                    $query->where('external_id', $messageId)
                        ->orWhere('headers->message-id', $messageId);
                })
                ->whereNotNull('conversation_id')
                ->first();

            if ($existing?->conversation_id) {
                return Conversation::query()->findOrFail($existing->conversation_id);
            }
        }

        $threadId = $candidateIds === []
            ? ($this->mapper->normalizeMessageId($incoming->headers['message-id'] ?? null) ?: $incoming->externalId)
            : end($candidateIds);

        return Conversation::query()->create([
            'project_id' => null,
            'subject' => $incoming->subject,
            'external_thread_id' => $threadId ?: $incoming->externalId,
        ]);
    }

    /**
     * @return list<string>
     */
    private function threadCandidateIds(NormalizedIncomingMessage $incoming): array
    {
        $ids = [];

        $inReplyTo = $this->mapper->normalizeMessageId($incoming->headers['in-reply-to'] ?? null);
        if ($inReplyTo !== '') {
            $ids[] = $inReplyTo;
        }

        $references = $incoming->headers['references'] ?? null;
        if (is_string($references) && trim($references) !== '') {
            if (preg_match_all('/<([^>]+)>/', $references, $matches) > 0) {
                foreach ($matches[1] as $ref) {
                    $normalized = $this->mapper->normalizeMessageId($ref);
                    if ($normalized !== '') {
                        $ids[] = $normalized;
                    }
                }
            } else {
                foreach (preg_split('/\s+/', $references) ?: [] as $ref) {
                    $normalized = $this->mapper->normalizeMessageId($ref);
                    if ($normalized !== '') {
                        $ids[] = $normalized;
                    }
                }
            }
        }

        return array_values(array_unique($ids));
    }
}
