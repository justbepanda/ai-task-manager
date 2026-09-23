<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Activity\ActivityLogRecorder;
use App\Domain\Projects\ApplyPeopleToProjectsBackfill;
use Illuminate\Console\Command;

class ApplyProjectPeopleCommand extends Command
{
    protected $signature = 'projects:apply-people
                            {--dry-run : Show which projects would be processed}
                            {--all : Include projects that already have a manager}
                            {--limit=50 : Max projects to process}';

    protected $description = 'Backfill project manager/executors from existing mail (Redmine rules + LLM)';

    public function handle(
        ApplyPeopleToProjectsBackfill $backfill,
        ActivityLogRecorder $activity,
    ): int {
        $onlyMissingManager = ! (bool) $this->option('all');
        $limit = (int) $this->option('limit');
        $dryRun = (bool) $this->option('dry-run');

        $result = $backfill->handle(
            onlyMissingManager: $onlyMissingManager,
            limit: $limit,
            dryRun: $dryRun,
        );

        $this->info(sprintf(
            'Projects scanned=%d, touched=%d, skipped_no_message=%d%s',
            $result['scanned'],
            $result['touched'],
            $result['skipped_no_message'],
            $dryRun ? ' (dry-run)' : '',
        ));

        foreach (array_slice($result['details'], 0, 30) as $row) {
            $this->line(sprintf(
                '  #%d %s ← message #%d manager=%s applied=%s',
                $row['project_id'],
                $row['project_name'],
                $row['message_id'],
                $row['manager_id'] ?? '—',
                $row['applied'] ? 'yes' : 'no',
            ));
        }

        if (count($result['details']) > 30) {
            $this->comment('  … ещё '.(count($result['details']) - 30));
        }

        if (! $dryRun) {
            $activity->record(
                category: 'projects',
                action: 'apply_people',
                status: 'ok',
                summary: sprintf(
                    'Менеджеры/исполнители: просмотрено %d, обновлено %d',
                    $result['scanned'],
                    $result['touched'],
                ),
                details: [
                    'scanned' => $result['scanned'],
                    'touched' => $result['touched'],
                    'skipped_no_message' => $result['skipped_no_message'],
                    'only_missing_manager' => $onlyMissingManager,
                    'limit' => $limit,
                    'source' => 'artisan',
                ],
            );
        }

        return self::SUCCESS;
    }
}
