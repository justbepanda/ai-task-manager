<?php

namespace App\Console\Commands;

use App\Domain\Metrics\HypothesisMetricsReport;
use Illuminate\Console\Command;

class HypothesisMetricsReportCommand extends Command
{
    protected $signature = 'metrics:hypothesis-report
                            {--json : Output raw JSON}
                            {--evaluate : Include Phase 8 real-mailbox checklist}';

    protected $description = 'Print §21 hypothesis metrics report (Russian-friendly)';

    public function handle(HypothesisMetricsReport $report): int
    {
        if ($this->option('evaluate')) {
            return $this->printEvaluation($report);
        }

        $snapshot = $report->snapshot();

        if ($this->option('json')) {
            $this->line(json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info('Метрики гипотезы (§21)');
        $this->line('Сформировано: '.$snapshot['generated_at']);
        $this->newLine();

        $rows = [];
        foreach ($snapshot['labels'] as $label => $value) {
            $rows[] = [$label, (string) $value];
        }
        $this->table(['Показатель', 'Значение'], $rows);

        $this->newLine();
        $this->comment('Сырые счётчики hypothesis_metric_events:');
        $eventRows = [];
        foreach ($snapshot['from_events'] as $name => $count) {
            if ($count > 0) {
                $eventRows[] = [$name, (string) $count];
            }
        }
        if ($eventRows === []) {
            $this->line('  (пока нет событий — выполните mail:sync и обработку писем)');
        } else {
            $this->table(['name', 'count'], $eventRows);
        }

        return self::SUCCESS;
    }

    private function printEvaluation(HypothesisMetricsReport $report): int
    {
        $evaluation = $report->phase8Evaluation();

        if ($this->option('json')) {
            $this->line(json_encode($evaluation, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info('Phase 8 — оценка реального ящика');
        $this->newLine();

        $metricRows = [];
        foreach ($evaluation['metrics'] as $label => $value) {
            $metricRows[] = [$label, (string) $value];
        }
        $this->table(['Метрика', 'Значение'], $metricRows);

        $this->newLine();
        $this->info('Чеклист Phase 8');
        $checkRows = [];
        foreach ($evaluation['checklist'] as $row) {
            $checkRows[] = [
                $row['item'],
                strtoupper((string) $row['status']),
                (string) $row['value'],
            ];
        }
        $this->table(['Пункт', 'Статус', 'Значение'], $checkRows);

        $this->newLine();
        $this->comment('Ручная проверка менеджером (корректность событий/draft) не автоматизирована.');

        return self::SUCCESS;
    }
}
