<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\ManagerActions\SupersedeStaleConversationManagerActions;
use Illuminate\Console\Command;

class SupersedeStaleManagerActionsCommand extends Command
{
    protected $signature = 'manager-actions:supersede-stale
                            {--dry-run : Show how many would be closed without writing}';

    protected $description = 'Close older pending ManagerActions when a newer one exists in the same conversation/task';

    public function handle(SupersedeStaleConversationManagerActions $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $result = $service->cleanupAll($dryRun);

        $this->info(sprintf(
            '%s: superseded=%d groups=%d',
            $dryRun ? 'Dry-run' : 'Done',
            $result['superseded'],
            $result['groups'],
        ));

        return self::SUCCESS;
    }
}
