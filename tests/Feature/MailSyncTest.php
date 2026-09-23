<?php

namespace Tests\Feature;

use App\Enums\MailSource;
use App\Enums\MessageProcessingStatus;
use App\Jobs\ClassifyMessageJob;
use App\Jobs\SyncYandexMailJob;
use App\Mail\DTO\NormalizedIncomingMessage;
use App\Mail\Exceptions\MailProviderException;
use App\Mail\FakeMailProvider;
use App\Mail\Contracts\MailProvider;
use App\Mail\Services\PersistIncomingMessage;
use App\Mail\Services\SyncMailService;
use App\Models\MailSyncState;
use App\Models\Message;
use App\Models\MessageRaw;
use App\Models\ProcessingLog;
use App\Models\ProjectTask;
use App\Enums\ProjectTaskSource;
use App\Enums\ProjectTaskStatus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MailSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_new_message_creates_message_raw_and_pending_status(): void
    {
        $incoming = $this->sampleMessage('msg-100@example.com', 100);

        $result = app(PersistIncomingMessage::class)->handle($incoming);

        $this->assertTrue($result['created']);
        $this->assertSame(MessageProcessingStatus::Pending, $result['message']->processing_status);
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseHas('message_raws', [
            'message_id' => $result['message']->id,
        ]);
        $this->assertDatabaseHas('processing_logs', [
            'message_id' => $result['message']->id,
            'step' => 'imported',
            'status' => 'ok',
        ]);
        $this->assertNotNull($result['message']->conversation_id);
        $this->assertNull($result['message']->project_id);
    }

    public function test_reimport_same_external_id_does_not_duplicate(): void
    {
        $incoming = $this->sampleMessage('msg-100@example.com', 100);
        $persist = app(PersistIncomingMessage::class);

        $first = $persist->handle($incoming);
        $second = $persist->handle($incoming);

        $this->assertTrue($first['created']);
        $this->assertFalse($second['created']);
        $this->assertSame($first['message']->id, $second['message']->id);
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseCount('message_raws', 1);
        $this->assertSame(1, ProcessingLog::query()->where('step', 'imported')->count());
    }

    public function test_provider_error_does_not_leak_secrets(): void
    {
        $secret = 'super-secret-app-password';
        $fake = new FakeMailProvider(
            exception: new MailProviderException('IMAP failed for user [redacted]: authentication error'),
        );
        $this->app->instance(MailProvider::class, $fake);

        try {
            app(SyncMailService::class)->sync(MailSource::Yandex);
            $this->fail('Expected MailProviderException');
        } catch (MailProviderException $e) {
            $this->assertStringNotContainsString($secret, $e->getMessage());
        }
    }

    public function test_yandex_normalized_message_maps_to_message_fields(): void
    {
        $incoming = new NormalizedIncomingMessage(
            source: MailSource::Yandex,
            externalId: 'abc123@yandex.ru',
            sender: 'Client <client@example.com>',
            recipients: ['manager@yandex.ru'],
            cc: ['cc@example.com'],
            subject: 'Сроки по задаче',
            body: 'Когда будет готово?',
            receivedAt: Carbon::parse('2026-09-01 12:00:00'),
            headers: [
                'message-id' => 'abc123@yandex.ru',
                'from' => 'Client <client@example.com>',
                'to' => 'manager@yandex.ru',
            ],
            metadata: [
                'imap_uid' => 42,
                'folder' => 'INBOX',
                'uidvalidity' => 9,
            ],
            rawHeaders: "Message-ID: <abc123@yandex.ru>\r\nFrom: Client <client@example.com>\r\n",
            rawBody: 'Когда будет готово?',
        );

        $result = app(PersistIncomingMessage::class)->handle($incoming);
        $message = $result['message']->fresh('raw');

        $this->assertSame(MailSource::Yandex, $message->source);
        $this->assertSame('abc123@yandex.ru', $message->external_id);
        $this->assertSame('Client <client@example.com>', $message->sender);
        $this->assertSame(['manager@yandex.ru'], $message->recipients);
        $this->assertSame(['cc@example.com'], $message->cc);
        $this->assertSame('Сроки по задаче', $message->subject);
        $this->assertSame('Когда будет готово?', $message->body);
        $this->assertSame(42, $message->metadata['imap_uid']);
        $this->assertInstanceOf(MessageRaw::class, $message->raw);
        $this->assertStringContainsString('Message-ID:', (string) $message->raw->raw_headers);
    }

    public function test_sync_mail_service_updates_last_uid_and_dispatches_classify_job(): void
    {
        Queue::fake();

        $fake = new FakeMailProvider([
            $this->sampleMessage('one@example.com', 10),
            $this->sampleMessage('two@example.com', 25),
        ]);
        $this->app->instance(MailProvider::class, $fake);

        $result = app(SyncMailService::class)->sync(MailSource::Yandex);

        $this->assertSame(2, $result['imported']);
        $this->assertSame(1, $result['folders']);
        $this->assertSame(25, $result['last_uid']);
        $this->assertDatabaseCount('messages', 2);

        $state = MailSyncState::query()->where('provider', 'yandex')->first();
        $this->assertNotNull($state);
        $this->assertSame(25, $state->last_uid);
        $this->assertNotNull($state->last_synced_at);

        Queue::assertPushed(ClassifyMessageJob::class, 2);
    }

    public function test_sync_imports_from_multiple_folders_including_trash(): void
    {
        Queue::fake();

        $fake = new FakeMailProvider(
            messages: [
                $this->sampleMessage('inbox@example.com', 10, folder: 'INBOX'),
                $this->sampleMessage('project@example.com', 3, folder: 'Projects/Alpha'),
                $this->sampleMessage('trash@example.com', 7, folder: 'Trash'),
            ],
            folders: ['INBOX', 'Projects/Alpha', 'Trash'],
        );
        $this->app->instance(MailProvider::class, $fake);

        $result = app(SyncMailService::class)->sync(MailSource::Yandex);

        $this->assertSame(3, $result['imported']);
        $this->assertSame(3, $result['folders']);
        $this->assertDatabaseCount('messages', 3);
        $this->assertDatabaseHas('mail_sync_states', ['provider' => 'yandex', 'folder' => 'INBOX', 'last_uid' => 10]);
        $this->assertDatabaseHas('mail_sync_states', ['provider' => 'yandex', 'folder' => 'Projects/Alpha', 'last_uid' => 3]);
        $this->assertDatabaseHas('mail_sync_states', ['provider' => 'yandex', 'folder' => 'Trash', 'last_uid' => 7]);
        Queue::assertPushed(ClassifyMessageJob::class, 3);
    }

    public function test_sync_skips_already_seen_uids_via_fake_provider(): void
    {
        Queue::fake();

        MailSyncState::query()->create([
            'provider' => 'yandex',
            'folder' => 'INBOX',
            'uidvalidity' => 1,
            'last_uid' => 20,
        ]);

        $fake = new FakeMailProvider([
            $this->sampleMessage('old@example.com', 10),
            $this->sampleMessage('new@example.com', 30),
        ]);
        $this->app->instance(MailProvider::class, $fake);

        $result = app(SyncMailService::class)->sync(MailSource::Yandex);

        $this->assertSame(1, $result['imported']);
        $this->assertSame(30, $result['last_uid']);
        $this->assertDatabaseHas('messages', ['external_id' => 'new@example.com']);
        $this->assertDatabaseMissing('messages', ['external_id' => 'old@example.com']);
    }

    public function test_sync_yandex_mail_job_runs_service(): void
    {
        Queue::fake([ClassifyMessageJob::class]);

        $fake = new FakeMailProvider([
            $this->sampleMessage('job@example.com', 5),
        ]);
        $this->app->instance(MailProvider::class, $fake);

        $this->app->call([new SyncYandexMailJob, 'handle']);

        $this->assertDatabaseHas('messages', ['external_id' => 'job@example.com']);
        Queue::assertPushed(ClassifyMessageJob::class, 1);
        $this->assertDatabaseHas('activity_logs', [
            'category' => 'mail',
            'action' => 'sync',
            'status' => 'ok',
        ]);
    }

    public function test_mail_sync_command_dispatches_job_by_default(): void
    {
        Queue::fake();

        $this->artisan('mail:sync')
            ->expectsOutput('SyncYandexMailJob dispatched to the queue.')
            ->assertSuccessful();

        Queue::assertPushed(SyncYandexMailJob::class);
    }

    public function test_mail_sync_command_runs_inline_with_sync_option(): void
    {
        Queue::fake();

        $fake = new FakeMailProvider([
            $this->sampleMessage('inline@example.com', 7),
        ]);
        $this->app->instance(MailProvider::class, $fake);

        $this->artisan('mail:sync', ['--sync' => true])
            ->assertSuccessful();

        $this->assertDatabaseHas('messages', ['external_id' => 'inline@example.com']);
        Queue::assertPushed(ClassifyMessageJob::class, 1);
        Queue::assertNotPushed(SyncYandexMailJob::class);
    }

    public function test_reply_links_to_existing_conversation(): void
    {
        $persist = app(PersistIncomingMessage::class);

        $root = $persist->handle($this->sampleMessage('root@example.com', 1, [
            'message-id' => 'root@example.com',
        ]));

        $reply = $persist->handle(new NormalizedIncomingMessage(
            source: MailSource::Yandex,
            externalId: 'reply@example.com',
            sender: 'client@example.com',
            recipients: ['me@yandex.ru'],
            cc: [],
            subject: 'Re: Hello',
            body: 'Thanks',
            receivedAt: Carbon::now(),
            headers: [
                'message-id' => 'reply@example.com',
                'in-reply-to' => 'root@example.com',
                'references' => '<root@example.com>',
            ],
            metadata: ['imap_uid' => 2, 'folder' => 'INBOX', 'uidvalidity' => 1],
            rawHeaders: null,
            rawBody: null,
        ));

        $this->assertSame($root['message']->conversation_id, $reply['message']->conversation_id);
    }

    public function test_empty_imap_message_is_skipped_not_persisted(): void
    {
        Queue::fake();

        $empty = new NormalizedIncomingMessage(
            source: MailSource::Yandex,
            externalId: 'broken-folder:0:5',
            sender: '',
            recipients: [],
            cc: [],
            subject: null,
            body: null,
            receivedAt: Carbon::now(),
            headers: [],
            metadata: [
                'imap_uid' => 5,
                'folder' => 'INBOX',
                'uidvalidity' => 0,
            ],
            rawHeaders: '',
            rawBody: '',
        );

        $fake = new FakeMailProvider([$empty, $this->sampleMessage('ok@example.com', 6)]);
        $this->app->instance(MailProvider::class, $fake);

        $result = app(SyncMailService::class)->sync(MailSource::Yandex);

        $this->assertSame(1, $result['imported']);
        $this->assertSame(1, $result['skipped']);
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseHas('messages', ['external_id' => 'ok@example.com']);
        $this->assertDatabaseHas('mail_sync_states', [
            'folder' => 'INBOX',
            'last_uid' => 6,
        ]);
    }

    public function test_persist_rejects_empty_message(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(PersistIncomingMessage::class)->handle(new NormalizedIncomingMessage(
            source: MailSource::Yandex,
            externalId: 'empty:0:1',
            sender: '',
            recipients: [],
            cc: [],
            subject: null,
            body: '',
            receivedAt: Carbon::now(),
            metadata: ['imap_uid' => 1, 'folder' => 'X', 'uidvalidity' => 0],
            rawHeaders: '',
            rawBody: '',
        ));
    }

    public function test_purge_empty_messages_command_deletes_blanks_and_resets_folder_cursor(): void
    {
        $folder = '&BBMEOAQzBDAEPwQ7BDAEPQ-, &BD8EOARBBEwEPAQw-';

        MailSyncState::query()->create([
            'provider' => MailSource::Yandex->value,
            'folder' => $folder,
            'last_uid' => 99,
            'uidvalidity' => 0,
        ]);

        Message::factory()->create([
            'sender' => '',
            'subject' => null,
            'body' => null,
            'metadata' => ['folder' => $folder, 'imap_uid' => 3, 'uidvalidity' => 0],
        ]);
        Message::factory()->create([
            'sender' => 'real@example.com',
            'subject' => 'OK',
            'body' => 'body',
        ]);

        ProjectTask::query()->create([
            'project_id' => null,
            'source' => ProjectTaskSource::EmailThread,
            'external_key' => 'orphan-thread:0:1',
            'title' => 'Переписка #999',
            'status' => ProjectTaskStatus::Open,
        ]);

        $this->artisan('messages:purge-empty')
            ->assertSuccessful();

        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseHas('messages', ['subject' => 'OK']);
        $this->assertDatabaseMissing('project_tasks', ['external_key' => 'orphan-thread:0:1']);
        $this->assertDatabaseHas('mail_sync_states', [
            'folder' => $folder,
            'last_uid' => 0,
        ]);
    }

    private function sampleMessage(string $externalId, int $uid, array $extraHeaders = [], string $folder = 'INBOX'): NormalizedIncomingMessage
    {
        return new NormalizedIncomingMessage(
            source: MailSource::Yandex,
            externalId: $externalId,
            sender: 'sender@example.com',
            recipients: ['inbox@yandex.ru'],
            cc: [],
            subject: 'Hello '.$externalId,
            body: 'Body for '.$externalId,
            receivedAt: Carbon::parse('2026-09-07 10:00:00'),
            headers: array_merge([
                'message-id' => $externalId,
                'from' => 'sender@example.com',
                'to' => 'inbox@yandex.ru',
            ], $extraHeaders),
            metadata: [
                'imap_uid' => $uid,
                'folder' => $folder,
                'uidvalidity' => 1,
            ],
            rawHeaders: "Message-ID: <{$externalId}>\r\nFrom: sender@example.com\r\n",
            rawBody: 'Body for '.$externalId,
        );
    }
}
