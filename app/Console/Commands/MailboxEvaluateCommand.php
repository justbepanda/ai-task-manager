<?php

namespace App\Console\Commands;

use App\Domain\Metrics\HypothesisMetricsReport;
use Illuminate\Console\Command;

class MailboxEvaluateCommand extends Command
{
    protected $signature = 'mailbox:evaluate {--json : Output raw JSON}';

    protected $description = 'Phase 8 real-mailbox evaluation: DB counts + checklist';

    public function handle(HypothesisMetricsReport $report): int
    {
        $params = ['--evaluate' => true];

        if ($this->option('json')) {
            $params['--json'] = true;
        }

        return $this->call('metrics:hypothesis-report', $params);
    }
}
