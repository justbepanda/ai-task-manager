<?php

namespace App\Console\Commands;

use App\Mail\Services\PurgeEmptyMessages;
use Illuminate\Console\Command;

class PurgeEmptyMessagesCommand extends Command
{
    protected $signature = 'messages:purge-empty {--dry-run : Show how many empty messages would be deleted}';

    protected $description = 'Delete messages with empty subject/sender/body (failed IMAP folder fetches) and reset their folder sync cursors';

    public function handle(PurgeEmptyMessages $purge): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $result = $purge->handle($dryRun);

        $this->info(($dryRun ? 'Would delete' : 'Deleted').": {$result['deleted']}");
        $this->info(($dryRun ? 'Would delete orphan tasks' : 'Orphan tasks deleted').": {$result['orphan_tasks_deleted']}");
        if (! $dryRun) {
            $this->info("Folders sync cursor reset: {$result['folders_reset']}");
        }

        return self::SUCCESS;
    }
}
