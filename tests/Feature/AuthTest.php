<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_logs_in_and_me_returns_user(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Бат',
            'email' => 'bat@example.com',
            'password' => 'secret-pass',
            'password_confirmation' => 'secret-pass',
        ])->assertCreated()->assertJsonPath('data.email', 'bat@example.com')->assertJsonPath('data.subscribed', false);

        $this->getJson('/api/v1/me')->assertJsonPath('data.name', 'Бат');
    }

    public function test_login_failure_and_logout(): void
    {
        User::factory()->create(['email' => 'a@example.com', 'password' => 'right-pass']);

        $this->postJson('/api/v1/auth/login', ['email' => 'a@example.com', 'password' => 'wrong'])
            ->assertStatus(422)->assertJsonValidationErrors('email');

        $this->postJson('/api/v1/auth/login', ['email' => 'a@example.com', 'password' => 'right-pass'])->assertOk();
        $this->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->getJson('/api/v1/me')->assertJsonPath('data', null);
    }

    public function test_guests_get_401_on_protected_routes(): void
    {
        $this->getJson('/api/v1/creations')->assertUnauthorized();
        $this->postJson('/api/v1/payments', ['plan_id' => 1])->assertUnauthorized();
    }

    public function test_spa_is_served_for_frontend_routes(): void
    {
        $this->withoutVite();
        $this->get('/create')->assertOk()->assertSee('id="app"', false);
    }
}
