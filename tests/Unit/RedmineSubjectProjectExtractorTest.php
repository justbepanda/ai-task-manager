<?php

namespace Tests\Unit;

use App\Domain\Projects\RedmineSubjectProjectExtractor;
use App\Models\Message;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RedmineSubjectProjectExtractorTest extends TestCase
{
    private RedmineSubjectProjectExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extractor = new RedmineSubjectProjectExtractor;
    }

    public function test_applies_to_configured_redmine_sender(): void
    {
        $message = new Message([
            'sender' => 'Redmine <redmine@kelnik.pro>',
            'subject' => '[Tekta - Bug #1] Test',
        ]);

        $this->assertTrue($this->extractor->appliesTo($message));
    }

    public function test_does_not_apply_to_other_senders(): void
    {
        $message = new Message([
            'sender' => 'client@example.com',
            'subject' => '[Tekta - Bug #1] Test',
        ]);

        $this->assertFalse($this->extractor->appliesTo($message));
    }

    #[DataProvider('subjectProvider')]
    public function test_extracts_name_candidates(string $subject, array $expectedSubset): void
    {
        $candidates = $this->extractor->extractNameCandidates($subject);

        foreach ($expectedSubset as $expected) {
            $this->assertContains($expected, $candidates);
        }
    }

    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function subjectProvider(): array
    {
        return [
            'tekta aliases' => [
                '[Tekta, Текта, ЛК клиента и брокера, поддержка - Bug #120] Что-то сломалось',
                ['Tekta', 'Текта', 'ЛК клиента и брокера', 'поддержка'],
            ],
            'rbi site' => [
                '[RBI сайт 2025 - New #99143] (Обратная связь) Форма на сайте',
                ['RBI сайт 2025'],
            ],
        ];
    }

    public function test_matches_project_by_first_alias(): void
    {
        $candidates = $this->extractor->extractNameCandidates(
            '[Tekta, Текта, ЛК клиента и брокера, поддержка - Support #5] Ping',
        );

        $this->assertSame('Tekta', $this->extractor->matchProjectName('Tekta', $candidates));
        $this->assertSame('Текта', $this->extractor->matchProjectName('Текта', $candidates));
    }

    public function test_matches_project_by_full_name_before_tracker(): void
    {
        $candidates = $this->extractor->extractNameCandidates(
            '[RBI сайт 2025 - New #99143] (Обратная связь)',
        );

        $this->assertSame(
            'RBI сайт 2025',
            $this->extractor->matchProjectName('RBI сайт 2025', $candidates),
        );
    }

    public function test_preferred_project_name_uses_first_alias(): void
    {
        $this->assertSame(
            'Tekta',
            $this->extractor->preferredProjectName(
                '[Tekta, Текта, ЛК клиента и брокера, поддержка - Bug #1] X',
            ),
        );

        $this->assertSame(
            'RBI сайт 2025',
            $this->extractor->preferredProjectName(
                '[RBI сайт 2025 - New #99143] (Обратная связь)',
            ),
        );
    }
}
