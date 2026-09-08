<?php

namespace Tests\Unit;

use App\Domain\Messages\ResolveMessageSenderPerson;
use App\Models\ClientContact;
use App\Models\Executor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ResolveMessageSenderPersonTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function matches_client_contact_by_email(): void
    {
        $contact = ClientContact::factory()->create([
            'last_name' => 'Иванов',
            'first_name' => 'Пётр',
            'middle_name' => null,
            'emails' => ['client@example.com'],
        ]);

        $result = app(ResolveMessageSenderPerson::class)->resolve('Пётр Иванов <client@example.com>');

        $this->assertNotNull($result);
        $this->assertSame('contact', $result['type']);
        $this->assertSame($contact->id, $result['id']);
        $this->assertSame('Иванов Пётр', $result['name']);
        $this->assertSame('Редактировать контакт', $result['label']);
        $this->assertStringContainsString((string) $contact->id, $result['route']);
    }

    #[Test]
    public function matches_executor_by_email(): void
    {
        $executor = Executor::factory()->create([
            'last_name' => 'Сидоров',
            'first_name' => 'Алексей',
            'middle_name' => null,
            'emails' => ['dev@agency.local'],
        ]);

        $result = app(ResolveMessageSenderPerson::class)->resolve('dev@agency.local');

        $this->assertNotNull($result);
        $this->assertSame('executor', $result['type']);
        $this->assertSame($executor->id, $result['id']);
        $this->assertSame('Сидоров Алексей', $result['name']);
        $this->assertSame('Редактировать исполнителя', $result['label']);
        $this->assertStringContainsString((string) $executor->id, $result['route']);
    }

    #[Test]
    public function returns_null_when_email_unknown(): void
    {
        $this->assertNull(app(ResolveMessageSenderPerson::class)->resolve('nobody@example.com'));
        $this->assertNull(app(ResolveMessageSenderPerson::class)->resolve(null));
        $this->assertNull(app(ResolveMessageSenderPerson::class)->resolve('not-an-email'));
    }
}
