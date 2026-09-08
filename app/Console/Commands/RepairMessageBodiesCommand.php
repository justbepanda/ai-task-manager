<?php

namespace App\Console\Commands;

use App\Mail\Imap\ImapMessageMapper;
use App\Models\Message;
use App\Models\MessageRaw;
use Illuminate\Console\Command;

class RepairMessageBodiesCommand extends Command
{
    protected $signature = 'messages:repair-bodies {--dry-run : Show how many would be updated}';

    protected $description = 'Re-parse subject/body from stored raw IMAP headers/bodies (strip HTML, fix MIME subjects)';

    public function handle(ImapMessageMapper $mapper): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $updated = 0;

        MessageRaw::query()
            ->with('message')
            ->orderBy('id')
            ->chunkById(100, function ($raws) use ($mapper, $dryRun, &$updated): void {
                foreach ($raws as $raw) {
                    /** @var MessageRaw $raw */
                    $message = $raw->message;
                    if (! $message instanceof Message) {
                        continue;
                    }

                    $source = $message->source;
                    $folder = (string) ($message->metadata['folder'] ?? 'INBOX');
                    $uid = (int) ($message->metadata['imap_uid'] ?? 0);
                    $uidvalidity = (int) ($message->metadata['uidvalidity'] ?? 0);

                    $normalized = $mapper->mapFromRaw(
                        rawHeaders: (string) ($raw->raw_headers ?? ''),
                        rawBody: (string) ($raw->raw_body ?? ''),
                        source: $source,
                        folder: $folder,
                        uid: max(1, $uid),
                        uidvalidity: max(1, $uidvalidity),
                    );

                    $dirty = $message->subject !== $normalized->subject
                        || $message->body !== $normalized->body;

                    if (! $dirty) {
                        continue;
                    }

                    $updated++;

                    if ($dryRun) {
                        continue;
                    }

                    $message->fill([
                        'subject' => $normalized->subject,
                        'body' => $normalized->body,
                        'headers' => $normalized->headers,
                    ]);
                    $message->save();
                }
            });

        $this->info(($dryRun ? 'Would update' : 'Updated').": {$updated}");

        return self::SUCCESS;
    }
}
