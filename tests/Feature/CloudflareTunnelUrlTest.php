<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchid\Support\Facades\Dashboard;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CloudflareTunnelUrlTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function admin_login_form_uses_public_https_url_behind_tunnel_headers(): void
    {
        $response = $this->withHeaders([
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'demo.trycloudflare.com',
        ])->get('http://localhost/admin/login');

        $response->assertOk();
        $response->assertDontSee('action="http://localhost', false);
        $response->assertSee('https://demo.trycloudflare.com', false);
    }

    #[Test]
    public function admin_can_authenticate_through_tunnel_headers(): void
    {
        $user = User::factory()->create([
            'email' => 'tunnel-admin@example.com',
            'password' => 'password',
            'permissions' => Dashboard::getAllowAllPermission(),
        ]);

        $response = $this->withHeaders([
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'demo.trycloudflare.com',
        ])->post('http://localhost/admin/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }
}
