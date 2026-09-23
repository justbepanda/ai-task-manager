<?php

declare(strict_types=1);

namespace App\Domain\Executors;

use App\Models\Executor;

/**
 * When an executor becomes a project manager automatically, reflect that on their profile.
 * Does not overwrite a non-empty custom position.
 */
class EnsureExecutorManagerRole
{
    public const POSITION = 'Менеджер';

    public function handle(Executor $executor): bool
    {
        $current = trim((string) ($executor->position ?? ''));
        if ($current !== '') {
            return false;
        }

        $executor->position = self::POSITION;
        $executor->save();

        return true;
    }
}
