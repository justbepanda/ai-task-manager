<?php

namespace Tests\Unit;

use App\Support\AdminLabels;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminLabelsTest extends TestCase
{
    #[Test]
    public function translates_metric_events_and_processing_steps(): void
    {
        $this->assertSame('Черновик ответа создан', AdminLabels::metricEvent('draft_created'));
        $this->assertSame('Письмо получено', AdminLabels::metricEvent('mail_received'));
        $this->assertSame('Импорт', AdminLabels::processingStep('imported'));
        $this->assertSame('Успешно', AdminLabels::processingStatus('ok'));
        $this->assertSame('Черновик ответа', AdminLabels::correctionField('draft_reply'));
        $this->assertSame('Классификация', AdminLabels::correctionField('classification'));
        $this->assertSame('Проект', AdminLabels::correctionField('project_id'));
        $this->assertSame('sender_role', AdminLabels::correctionField('sender_role'));
    }
}
