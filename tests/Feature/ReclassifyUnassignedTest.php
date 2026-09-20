<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\ReprocessMessagesCommand;
use App\Enums\MessageClassification;
use App\Enums\MessageProcessingStatus;
use App\Jobs\AnalyzeMessageJob;
use App\Jobs\ApplyEventJob;
use App\Jobs\ClassifyMessageJob;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class ReclassifyUnassignedTest extends TestCase
{
    use RefreshDatabase;

    public function test_reclassify_unassigned_resets_classified_without_project(): void
    {
        Bus::fake([ClassifyMessageJob::class, AnalyzeMessageJob::class, ApplyEventJob::class]);

        $conversation = Conversation::query()->create([
            'project_id' => null,
            'subject' => 'S',
            'external_thread_id' => 't1',
        ]);

        $unassigned = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'project_id' => null,
            'classification' => MessageClassification::Technical,
            'processing_status' => MessageProcessingStatus::Classified,
            'ai_summary' => 'old',
            'ai_confidence' => 0.5,
            'requires_manager' => false,
        ]);

        $ignored = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'project_id' => null,
            'classification' => MessageClassification::Ignored,
            'processing_status' => MessageProcessingStatus::Classified,
        ]);

        Artisan::call(ReprocessMessagesCommand::class, [
            '--reclassify-unassigned' => true,
            '--dry-run' => true,
        ]);
        $this->assertStringContainsString('reclassify reset=1', Artisan::output());

        Artisan::call(ReprocessMessagesCommand::class, [
            '--reclassify-unassigned' => true,
        ]);

        $unassigned->refresh();
        $ignored->refresh();

        $this->assertSame(MessageProcessingStatus::Pending, $unassigned->processing_status);
        $this->assertNull($unassigned->classification);
        $this->assertNull($unassigned->ai_summary);
        $this->assertNull($unassigned->ai_confidence);
        $this->assertNull($unassigned->requires_manager);

        $this->assertSame(MessageProcessingStatus::Classified, $ignored->processing_status);
        $this->assertSame(MessageClassification::Ignored, $ignored->classification);

        Bus::assertDispatched(ClassifyMessageJob::class);
    }
}
