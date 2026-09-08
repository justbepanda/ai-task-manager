<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Orchid\Support\Facades\Dashboard;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = Dashboard::getAllowAllPermission();

        User::query()->where('email', 'admin@ai-tasks.local')->delete();

        $user = User::query()->updateOrCreate(
            ['email' => 'developer@kelnik.ru'],
            [
                'name' => 'Developer',
                'password' => 'developer',
                'permissions' => $permissions,
            ],
        );

        $user->forceFill([
            'permissions' => $permissions,
        ])->save();
    }
}
