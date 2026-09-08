<?php

namespace App\Jobs;

use App\Domain\Events\ApplyProjectEventService;
use App\Models\Event;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ApplyEventJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public int $eventId,
    ) {}

    public function handle(ApplyProjectEventService $applyProjectEvent): void
    {
        $event = Event::query()->find($this->eventId);

        if ($event === null) {
            Log::warning('ApplyEventJob: event not found', [
                'event_id' => $this->eventId,
            ]);

            return;
        }

        $result = $applyProjectEvent->handle($event);

        Log::info('ApplyEventJob finished', [
            'event_id' => $this->eventId,
            'applied' => $result['applied'],
            'skipped' => $result['skipped'],
            'noop' => $result['noop'],
            'reason' => $result['reason'],
            'manager_action_id' => $result['manager_action_id'],
        ]);
    }
}
