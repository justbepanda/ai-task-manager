<?php

declare(strict_types=1);

namespace App\Domain\Ops;

use App\Domain\Activity\ActivityLogRecorder;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

class RunAdminArtisanAction
{
    public function __construct(
        private readonly ActivityLogRecorder $activity,
    ) {}

    /**
     * @return array{ok: bool, output: string, summary: string, requires_relogin: bool}
     */
    public function handle(string $actionId): array
    {
        $action = AdminArtisanCatalog::find($actionId);
        if ($action === null) {
            throw new RuntimeException('Неизвестное действие: '.$actionId);
        }

        $output = new BufferedOutput;
        $exitCode = 0;

        try {
            $exitCode = Artisan::call($action['command'], $action['options'], $output);
            $text = trim($output->fetch());
            $ok = $exitCode === 0;
        } catch (Throwable $e) {
            $ok = false;
            $text = $e->getMessage();
            $exitCode = 1;
        }

        // After wiping the DB, Redis still holds old job payloads (ClassifyMessageJob for deleted IDs).
        // Clear the default queue so the next mail:sync starts clean.
        if ($actionId === 'db_fresh_seed' && $ok) {
            try {
                Artisan::call('queue:clear', ['connection' => 'redis', '--queue' => 'default'], $output);
                $cleared = trim($output->fetch());
                if ($cleared !== '') {
                    $text = trim($text."\n".$cleared);
                }
            } catch (Throwable $e) {
                $text = trim($text."\nqueue:clear failed: ".$e->getMessage());
            }
        }

        $summary = sprintf(
            '%s (%s): %s',
            $action['title'],
            $action['command'],
            $ok ? 'успех' : 'ошибка #'.$exitCode,
        );

        $userId = Auth::id();
        if ($actionId === 'db_fresh_seed') {
            // Old session user was wiped; attribute log to reseeded admin if present.
            $userId = User::query()->where('email', 'developer@kelnik.ru')->value('id');
            $userId = is_numeric($userId) ? (int) $userId : null;
        }

        try {
            $this->activity->record(
                category: 'system',
                action: 'admin_tool',
                status: $ok ? 'ok' : 'failed',
                summary: $summary,
                details: [
                    'tool_id' => $action['id'],
                    'command' => $action['command'],
                    'options' => $action['options'],
                    'exit_code' => $exitCode,
                    'output' => mb_substr($text !== '' ? $text : '(нет вывода)', 0, 2000),
                ],
                userId: $userId,
            );
        } catch (Throwable) {
            // Do not hide the artisan result if journaling fails after destructive ops.
        }

        return [
            'ok' => $ok,
            'output' => $text !== '' ? $text : '(команда завершилась без текста)',
            'summary' => $summary,
            'requires_relogin' => $actionId === 'db_fresh_seed' && $ok,
        ];
    }
}
