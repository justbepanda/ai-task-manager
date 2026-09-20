<?php

namespace App\Console\Commands;

use App\Domain\Activity\ActivityLogRecorder;
use App\Domain\Mail\ApplyMailFiltersToMessages;
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
                            {--reclassify-manual : Reset needs_manual messages to pending and re-run classify (use after adding projects/rules)}
                            {--reclassify-unassigned : Reset non-ignored messages without project_id to pending and re-run full Hermes pipeline (Redmine auto-create / LLM assign / analysis)}
                            {--apply-mail-filters : Re-check already imported mail against current Фильтры почты and mark matches as ignored}';

    protected $description = 'Re-dispatch pipeline jobs for stuck messages (pending classify, client without events, unapplied events)';

    public function handle(
        ApplyMailFiltersToMessages $applyMailFilters,
        ActivityLogRecorder $activity,
    ): int {
        $resetCount = 0;
        $filterApplied = 0;

        if ($this->option('apply-mail-filters')) {
            $filterApplied = $applyMailFilters->apply((bool) $this->option('dry-run'));

            if (! $this->option('dry-run')) {
                $activity->record(
                    category: 'mail_filters',
                    action: 'rebuild_ignored',
                    status: 'ok',
                    summary: "Пересборка игнорируемых (CLI): изменено писем — {$filterApplied}",
                    details: ['touched' => $filterApplied, 'source' => 'artisan'],
                );
            }
        }

        if ($this->option('reclassify-unassigned')) {
            $query = Message::query()
                ->whereNull('project_id')
                ->where(function ($q): void {
                    $q->whereNull('classification')
                        ->orWhere('classification', '!=', MessageClassification::Ignored->value);
                })
                ->whereIn('processing_status', [
                    MessageProcessingStatus::Classified,
                    MessageProcessingStatus::NeedsManual,
                    MessageProcessingStatus::Analyzed,
                    MessageProcessingStatus::Failed,
                ]);

            $resetCount = $this->option('dry-run')
                ? $query->count()
                : $query->update([
                    'processing_status' => MessageProcessingStatus::Pending->value,
                    'classification' => null,
                    'ai_summary' => null,
                    'ai_confidence' => null,
                    'requires_manager' => null,
                    'processing_error' => null,
                ]);
        } elseif ($this->option('reclassify-manual') && ! $this->option('dry-run')) {
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
                MessageClassification::Technical,
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
            'Mail-filters applied=%d, reclassify reset=%d, pending classify=%d, analyze without events=%d, unapplied events=%d',
            $filterApplied,
            $resetCount,
            $pendingIds->count(),
            $analyzeIds->count(),
            $unappliedEventIds->count(),
        ));

        if ($this->option('dry-run')) {
            $this->comment('Dry run — no jobs dispatched / no filter writes.');

            return self::SUCCESS;
        }

        if ($this->option('apply-mail-filters')
            && ! $this->option('reclassify-manual')
            && $pendingIds->isEmpty()
            && $analyzeIds->isEmpty()
            && $unappliedEventIds->isEmpty()
        ) {
            $this->info('Mail filters applied to existing messages.');

            return self::SUCCESS;
        }

        $sync = (bool) $this->option('sync');

        // Apply first: cheap, and unblocks ManagerActions even when classify backlog is large.
        foreach ($unappliedEventIds as $id) {
            $sync
                ? ApplyEventJob::dispatchSync($id)
                : ApplyEventJob::dispatch($id);
        }

        foreach ($analyzeIds as $id) {
            $sync
                ? AnalyzeMessageJob::dispatchSync($id)
                : AnalyzeMessageJob::dispatch($id);
        }

        foreach ($pendingIds as $id) {
            $sync
                ? ClassifyMessageJob::dispatchSync($id)
                : ClassifyMessageJob::dispatch($id);
        }

        $this->info($sync
            ? 'Jobs executed inline.'
            : 'Jobs dispatched to the queue.');

        return self::SUCCESS;
    }
}
