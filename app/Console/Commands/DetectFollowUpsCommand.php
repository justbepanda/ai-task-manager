<?php

namespace App\Console\Commands;

use App\Domain\ManagerActions\DetectClientFollowUps;
use App\Jobs\DetectClientFollowUpsJob;
use Illuminate\Console\Command;

class DetectFollowUpsCommand extends Command
{
    protected $signature = 'messages:detect-follow-ups
                            {--sync : Run inline instead of queueing}';

    protected $description = 'Create follow_up ManagerActions for silent client conversations';

    public function handle(DetectClientFollowUps $detector): int
    {
        if ($this->option('sync')) {
            $result = $detector->handle();
            $this->info(sprintf(
                'Follow-up scan: created=%d scanned=%d',
                $result['created'],
                $result['scanned'],
            ));

            return self::SUCCESS;
        }

        DetectClientFollowUpsJob::dispatch();
        $this->info('DetectClientFollowUpsJob dispatched to the queue.');

        return self::SUCCESS;
    }
}
