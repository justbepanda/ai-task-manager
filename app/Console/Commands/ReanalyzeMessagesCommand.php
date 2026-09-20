<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Messages\ReanalyzeMessage;
use App\Models\Message;
use Illuminate\Console\Command;

class ReanalyzeMessagesCommand extends Command
{
    protected $signature = 'messages:reanalyze
                            {ids* : Message IDs to re-run Hermes analysis for}
                            {--queue : Dispatch to queue instead of running inline}';

    protected $description = 'Delete prior Event/ManagerAction for messages and re-run Hermes analysis (uses latest-reply body)';

    public function handle(ReanalyzeMessage $reanalyze): int
    {
        $ids = array_values(array_unique(array_map('intval', $this->argument('ids'))));
        $sync = ! $this->option('queue');

        foreach ($ids as $id) {
            $message = Message::query()->find($id);
            if ($message === null) {
                $this->warn("Message #{$id} not found");

                continue;
            }

            $reanalyze->handle($message, sync: $sync);
            $this->info($sync
                ? "Reanalyzed message #{$id}"
                : "Queued reanalyze for message #{$id}");
        }

        return self::SUCCESS;
    }
}
