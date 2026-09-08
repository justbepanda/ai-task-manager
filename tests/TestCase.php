<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Orchid\Support\Facades\Dashboard;
use Orchid\Support\Testing\ScreenTesting;

abstract class TestCase extends BaseTestCase
{
    use ScreenTesting;

    protected function makeAdmin(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'email' => 'admin-'.uniqid('', true).'@ai-tasks.local',
            'password' => 'password',
            'permissions' => Dashboard::getAllowAllPermission(),
        ], $overrides));
    }
}
