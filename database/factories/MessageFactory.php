<?php

namespace Database\Factories;

use App\Enums\MailSource;
use App\Enums\MessageProcessingStatus;
use App\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    protected $model = Message::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source' => MailSource::Yandex,
            'external_id' => fake()->unique()->uuid(),
            'sender' => fake()->safeEmail(),
            'recipients' => [fake()->safeEmail()],
            'cc' => null,
            'subject' => fake()->sentence(),
            'body' => fake()->paragraphs(2, true),
            'received_at' => fake()->dateTimeBetween('-7 days'),
            'headers' => null,
            'metadata' => null,
            'classification' => null,
            'processing_status' => MessageProcessingStatus::Pending,
            'ai_summary' => null,
            'ai_confidence' => null,
            'requires_manager' => null,
            'processing_error' => null,
        ];
    }
}
