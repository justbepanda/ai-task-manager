<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Dashboard;

use Orchid\Screen\Layout;
use Orchid\Screen\Repository;

/**
 * Like Orchid Metric, but each card may include a clickable `url`.
 */
final class ClickableMetricsLayout extends Layout
{
    protected $template = 'orchid.dashboard.metrics';

    /**
     * @param  array<string, string>  $labels  Visible label => repository path
     */
    public function __construct(
        private readonly array $labels,
    ) {}

    public function build(Repository $repository)
    {
        $this->query = $repository;

        if (! $this->isSee() || $this->labels === []) {
            return;
        }

        $metrics = [];
        foreach ($this->labels as $label => $path) {
            $data = $repository->getContent($path, ['value' => 0]);
            if (! is_array($data)) {
                $data = ['value' => $data];
            }
            $metrics[$label] = $data;
        }

        return view($this->template, [
            'metrics' => $metrics,
        ]);
    }
}
