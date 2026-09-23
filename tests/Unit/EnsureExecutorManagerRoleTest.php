<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Executors\EnsureExecutorManagerRole;
use App\Models\Executor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EnsureExecutorManagerRoleTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function sets_manager_when_position_empty(): void
    {
        $executor = Executor::factory()->create(['position' => null]);

        $changed = app(EnsureExecutorManagerRole::class)->handle($executor);

        $this->assertTrue($changed);
        $this->assertSame('Менеджер', $executor->fresh()->position);
    }

    #[Test]
    public function does_not_overwrite_existing_position(): void
    {
        $executor = Executor::factory()->create(['position' => 'Разработчик']);

        $changed = app(EnsureExecutorManagerRole::class)->handle($executor);

        $this->assertFalse($changed);
        $this->assertSame('Разработчик', $executor->fresh()->position);
    }
}
