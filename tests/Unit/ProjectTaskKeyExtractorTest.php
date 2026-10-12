<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Tasks\ProjectTaskKeyExtractor;
use App\Enums\ProjectTaskSource;
use App\Enums\ProjectTaskStatus;
use App\Models\Message;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProjectTaskKeyExtractorTest extends TestCase
{
    #[Test]
    public function extracts_redmine_issue_and_feedback_status(): void
    {
        $message = new Message([
            'subject' => '[RBI, РБИ - Support #99139] (Обратная связь) Пропуск выбора этажа',
        ]);

        $result = (new ProjectTaskKeyExtractor)->extract($message);

        $this->assertNotNull($result);
        $this->assertSame(ProjectTaskSource::Redmine, $result['source']);
        $this->assertSame('99139', $result['external_key']);
        $this->assertSame(ProjectTaskStatus::Feedback, $result['status']);
        $this->assertStringContainsString('Пропуск выбора этажа', (string) $result['title']);
    }

    #[Test]
    public function extracts_jira_key(): void
    {
        $message = new Message([
            'subject' => '[Jira] (GIG-32) «Вёрстка | Форма обратной связи»',
        ]);

        $result = (new ProjectTaskKeyExtractor)->extract($message);

        $this->assertNotNull($result);
        $this->assertSame(ProjectTaskSource::Jira, $result['source']);
        $this->assertSame('GIG-32', $result['external_key']);
    }

    #[Test]
    public function extracts_aspro_notification_subject(): void
    {
        $message = new Message([
            'subject' => 'Уведомление Задача: STAVNI — правка колл-трекинга',
        ]);

        $result = (new ProjectTaskKeyExtractor)->extract($message);

        $this->assertNotNull($result);
        $this->assertSame(ProjectTaskSource::Aspro, $result['source']);
        $this->assertStringStartsWith('aspro:', $result['external_key']);
        $this->assertStringContainsString('STAVNI', (string) $result['title']);

        $again = (new ProjectTaskKeyExtractor)->extract(new Message([
            'subject' => 'Уведомление Задача: STAVNI — правка колл-трекинга',
        ]));
        $this->assertSame($result['external_key'], $again['external_key']);
    }

    #[Test]
    public function returns_null_for_plain_subject(): void
    {
        $message = new Message(['subject' => 'Re: доработки по сайту']);

        $this->assertNull((new ProjectTaskKeyExtractor)->extract($message));
    }
}
