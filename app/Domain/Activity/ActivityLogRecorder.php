<?php

declare(strict_types=1);

namespace App\Domain\Activity;

use App\Models\ActivityLog;
use App\Support\SensitiveDataSanitizer;
use Illuminate\Support\Facades\Auth;

class ActivityLogRecorder
{
    /**
     * @param  array<string, mixed>|null  $details
     */
    public function record(
        string $category,
        string $action,
        string $status = 'ok',
        ?string $summary = null,
        ?array $details = null,
        ?int $messageId = null,
        ?int $userId = null,
    ): ActivityLog {
        return ActivityLog::query()->create([
            'category' => $category,
            'action' => $action,
            'status' => $status,
            'summary' => $summary !== null ? mb_substr($summary, 0, 500) : null,
            'details' => SensitiveDataSanitizer::sanitizeDetails($details),
            'message_id' => $messageId,
            'user_id' => $userId ?? Auth::id(),
        ]);
    }
}
