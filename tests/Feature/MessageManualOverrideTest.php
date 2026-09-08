<?php

namespace Tests\Feature;

use App\Domain\Messages\MessageManualOverrideService;
use App\Enums\MessageClassification;
use App\Enums\MessageProcessingStatus;
use App\Enums\ProjectStatus;
use App\Models\Message;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MessageManualOverrideTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function changing_classification_writes_analysis_correction(): void
    {
        $message = Message::factory()->create([
            'classification' => MessageClassification::Unknown,
            'processing_status' => MessageProcessingStatus::NeedsManual,
            'project_id' => null,
        ]);

        app(MessageManualOverrideService::class)->apply($message, [
            'classification' => MessageClassification::Client,
        ]);

        $this->assertDatabaseHas('analysis_corrections', [
            'message_id' => $message->id,
            'field' => 'classification',
            'ai_value' => 'unknown',
            'manager_value' => 'client',
        ]);

        $this->assertSame(MessageClassification::Client, $message->fresh()->classification);
        $this->assertSame(MessageProcessingStatus::Classified, $message->fresh()->processing_status);
    }

    #[Test]
    public function changing_project_writes_analysis_correction(): void
    {
        $project = Project::factory()->create(['status' => ProjectStatus::Active]);
        $message = Message::factory()->create([
            'classification' => MessageClassification::Client,
            'processing_status' => MessageProcessingStatus::NeedsManual,
            'project_id' => null,
        ]);

        app(MessageManualOverrideService::class)->apply($message, [
            'project_id' => $project->id,
        ]);

        $this->assertDatabaseHas('analysis_corrections', [
            'message_id' => $message->id,
            'field' => 'project_id',
            'ai_value' => null,
            'manager_value' => (string) $project->id,
        ]);

        $this->assertSame($project->id, $message->fresh()->project_id);
        $this->assertTrue(app(MessageManualOverrideService::class)->shouldQueueAnalyze($message->fresh()));
    }

    #[Test]
    public function should_not_queue_analyze_for_technical_even_with_project(): void
    {
        $project = Project::factory()->create(['status' => ProjectStatus::Active]);
        $message = Message::factory()->create([
            'classification' => MessageClassification::Technical,
            'processing_status' => MessageProcessingStatus::Classified,
            'project_id' => $project->id,
        ]);

        $this->assertFalse(app(MessageManualOverrideService::class)->shouldQueueAnalyze($message));
    }
}
