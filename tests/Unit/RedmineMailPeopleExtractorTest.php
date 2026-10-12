<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Projects\RedmineMailPeopleExtractor;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RedmineMailPeopleExtractorTest extends TestCase
{
    private RedmineMailPeopleExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extractor = new RedmineMailPeopleExtractor;
    }

    #[Test]
    public function extracts_author_assignee_and_feedback_flag(): void
    {
        $subject = '[Тушино - Bug #1] (Обратная связь) Импорт';
        $body = "Задача #1 обновлена.\nАвтор: Иван Петров\nНазначена: Евгений Сипров\nСтатус: Обратная связь\n";

        $result = $this->extractor->extract($subject, $body);

        $this->assertSame('Иван Петров', $result['author']);
        $this->assertSame('Евгений Сипров', $result['assignee']);
        $this->assertTrue($result['requires_feedback']);
    }

    #[Test]
    public function extracts_naznachen_masculine_and_author_email(): void
    {
        $result = $this->extractor->extract(
            '[X - New #2] Тема',
            "Автор: redmine@example.com\nНазначен: Пётр Иванов\n",
        );

        $this->assertSame('redmine@example.com', $result['author']);
        $this->assertSame('Пётр Иванов', $result['assignee']);
        $this->assertFalse($result['requires_feedback']);
    }

    #[Test]
    public function extracts_author_with_bullet_marker(): void
    {
        $result = $this->extractor->extract(
            '[Tekta - Bug #13] (В работе) Fix',
            "* Автор: Константин Плашиннов\n* Назначена: Юрий Устинов\n",
        );

        $this->assertSame('Константин Плашиннов', $result['author']);
        $this->assertSame('Юрий Устинов', $result['assignee']);
    }

    #[Test]
    public function feedback_detection_is_case_insensitive(): void
    {
        $this->assertTrue($this->extractor->subjectRequiresFeedback('[A - Bug #3] (обратная СВЯЗЬ) x'));
        $this->assertFalse($this->extractor->subjectRequiresFeedback('[A - Bug #3] (В работе) x'));
    }
}
