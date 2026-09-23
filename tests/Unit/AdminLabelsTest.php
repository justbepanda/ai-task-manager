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
        $this->assertSame('Проект создан автоматически', AdminLabels::metricEvent('project_auto_created'));
        $this->assertSame('Проект создан автоматически', AdminLabels::processingStep('project_auto_created'));
        $this->assertSame('Распределение через LLM', AdminLabels::processingStep('project_auto_assigned_llm'));
        $this->assertSame('Redmine: люди проекта', AdminLabels::processingStep('redmine_people_applied'));
        $this->assertSame('LLM: менеджер и исполнители', AdminLabels::processingStep('llm_people_applied'));
        $this->assertSame('Redmine: обратная связь', AdminLabels::processingStep('redmine_feedback_requires_manager'));
        $this->assertSame('Импорт', AdminLabels::processingStep('imported'));
        $this->assertSame('Успешно', AdminLabels::processingStatus('ok'));
        $this->assertSame('Черновик ответа', AdminLabels::correctionField('draft_reply'));
        $this->assertSame('Классификация', AdminLabels::correctionField('classification'));
        $this->assertSame('Проект', AdminLabels::correctionField('project_id'));
        $this->assertSame('Роль отправителя', AdminLabels::correctionField('sender_role'));
        $this->assertSame('проект', AdminLabels::detailKey('project_id'));
        $this->assertSame('уверенность', AdminLabels::detailKey('confidence'));
    }
}
