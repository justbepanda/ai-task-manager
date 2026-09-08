<?php

namespace App\Console\Commands;

use App\Enums\MessageClassification;
use App\Enums\MessageProcessingStatus;
use App\Jobs\AnalyzeMessageJob;
use App\Jobs\ApplyEventJob;
use App\Jobs\ClassifyMessageJob;
use App\Models\Event;
use App\Models\Message;
use Illuminate\Console\Command;

class ReprocessMessagesCommand extends Command
{
    protected $signature = 'messages:reprocess
                            {--dry-run : Show counts without dispatching jobs}
                            {--sync : Run jobs inline instead of queueing}
                            {--reclassify-manual : Reset needs_manual messages to pending and re-run classify (use after adding projects/rules)}';

    protected $description = 'Re-dispatch pipeline jobs for stuck messages (pending classify, client without events, unapplied events)';

    public function handle(): int
    {
        $resetCount = 0;

        if ($this->option('reclassify-manual') && ! $this->option('dry-run')) {
            $resetCount = Message::query()
                ->where('processing_status', MessageProcessingStatus::NeedsManual)
                ->whereDoesntHave('events')
                ->update([
                    'processing_status' => MessageProcessingStatus::Pending->value,
                    'classification' => null,
                    'project_id' => null,
                    'processing_error' => null,
                ]);
        } elseif ($this->option('reclassify-manual') && $this->option('dry-run')) {
            $resetCount = Message::query()
                ->where('processing_status', MessageProcessingStatus::NeedsManual)
                ->whereDoesntHave('events')
                ->count();
        }

        $pendingIds = Message::query()
            ->where('processing_status', MessageProcessingStatus::Pending)
            ->orderBy('id')
            ->pluck('id');

        $analyzeIds = Message::query()
            ->whereIn('processing_status', [
                MessageProcessingStatus::Classified,
                MessageProcessingStatus::NeedsManual,
            ])
            ->whereIn('classification', [
                MessageClassification::Client,
                MessageClassification::Unknown,
            ])
            ->whereNotNull('project_id')
            ->whereDoesntHave('events')
            ->orderBy('id')
            ->pluck('id');

        $unappliedEventIds = Event::query()
            ->whereNull('applied_at')
            ->orderBy('id')
            ->pluck('id');

        $this->info(sprintf(
            'Reclassify-manual reset=%d, pending classify=%d, analyze without events=%d, unapplied events=%d',
            $resetCount,
            $pendingIds->count(),
            $analyzeIds->count(),
            $unappliedEventIds->count(),
        ));

        if ($this->option('dry-run')) {
            $this->comment('Dry run — no jobs dispatched.');

            return self::SUCCESS;
        }

        $sync = (bool) $this->option('sync');

        foreach ($pendingIds as $id) {
            $sync
                ? ClassifyMessageJob::dispatchSync($id)
                : ClassifyMessageJob::dispatch($id);
        }

        foreach ($analyzeIds as $id) {
            $sync
                ? AnalyzeMessageJob::dispatchSync($id)
                : AnalyzeMessageJob::dispatch($id);
        }

        foreach ($unappliedEventIds as $id) {
            $sync
                ? ApplyEventJob::dispatchSync($id)
                : ApplyEventJob::dispatch($id);
        }

        $this->info($sync
            ? 'Jobs executed inline.'
            : 'Jobs dispatched to the queue.');

        return self::SUCCESS;
    }
}
