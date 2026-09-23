<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Tasks\ResolveOrCreateProjectTask;
use App\Enums\MessageClassification;
use App\Models\Message;
use Illuminate\Console\Command;

class RebuildProjectTasksCommand extends Command
{
    protected $signature = 'tasks:rebuild
                            {--limit=0 : Max messages to process (0 = all)}';

    protected $description = 'Create/attach ProjectTask for existing messages (Redmine/Jira keys or email threads)';

    public function handle(ResolveOrCreateProjectTask $resolver): int
    {
        $query = Message::query()
            ->where(function ($q): void {
                $q->whereNull('classification')
                    ->orWhere('classification', '!=', MessageClassification::Ignored->value);
            })
            ->orderBy('id');

        $limit = (int) $this->option('limit');
        if ($limit > 0) {
            $query->limit($limit);
        }

        $processed = 0;
        $linked = 0;

        $query->chunkById(100, function ($messages) use ($resolver, &$processed, &$linked): void {
            foreach ($messages as $message) {
                $processed++;
                $task = $resolver->handle($message);
                if ($task !== null) {
                    $linked++;
                }
            }
        });

        $this->info("Processed={$processed}, linked_to_task={$linked}");

        return self::SUCCESS;
    }
}
