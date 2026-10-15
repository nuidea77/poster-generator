<?php

namespace Tests\Feature;

use App\Models\Creation;
use App\Models\User;
use App\Services\Agent\DesignMemory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Хэрэглэгчийн үнэлгээ (👍/👎) ба түүнээс суралцах DesignMemory.
class FeedbackTest extends TestCase
{
    use RefreshDatabase;

    // Дууссан постер; steps-д deliver_poster-ийн design-тай.
    private function poster(User $user, array $design = [], array $attrs = []): Creation
    {
        $steps = $design ? [
            ['name' => 'deliver_poster', 'input' => ['design' => ['map' => 'hero'], 'asset' => 'img_1'], 'error' => 'refused'],
            ['name' => 'deliver_poster', 'input' => ['design' => $design, 'asset' => 'img_2'], 'result' => 'ok'],
        ] : [];

        return Creation::create($attrs + ['user_id' => $user->id, 'type' => Creation::POSTER, 'prompt' => 'Кофе', 'status' => Creation::DONE, 'steps' => $steps]);
    }

    public function test_owner_rates_a_done_creation(): void
    {
        $user = User::factory()->create();
        $c = $this->poster($user);

        $this->actingAs($user)->postJson("/api/v1/creations/{$c->public_id}/rating", ['rating' => -1, 'feedback' => '  Өнгө хэт бараан  '])
            ->assertOk()->assertJsonPath('data.rating', -1)->assertJsonPath('data.feedback', 'Өнгө хэт бараан');
        $this->assertNotNull($c->fresh()->rated_at);

        $this->actingAs($user)->postJson("/api/v1/creations/{$c->public_id}/rating", ['rating' => 1])
            ->assertOk()->assertJsonPath('data.rating', 1)->assertJsonPath('data.feedback', null);
        $this->actingAs($user)->getJson("/api/v1/creations/{$c->public_id}")->assertJsonPath('data.rating', 1);
    }

    public function test_rating_is_owner_only_validated_and_done_only(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $c = $this->poster($user);

        $this->actingAs($other)->postJson("/api/v1/creations/{$c->public_id}/rating", ['rating' => 1])->assertNotFound();
        $this->actingAs($admin)->postJson("/api/v1/creations/{$c->public_id}/rating", ['rating' => 1])->assertNotFound();
        $this->assertNull($c->fresh()->rating);

        foreach ([[], ['rating' => 0], ['rating' => 5], ['rating' => 'like'], ['rating' => 1, 'feedback' => str_repeat('а', 501)]] as $body) {
            $this->actingAs($user)->postJson("/api/v1/creations/{$c->public_id}/rating", $body)->assertUnprocessable();
        }

        $running = $this->poster($user, [], ['status' => Creation::RUNNING]);
        $failed = $this->poster($user, [], ['status' => Creation::FAILED]);
        $this->actingAs($user)->postJson("/api/v1/creations/{$running->public_id}/rating", ['rating' => 1])->assertStatus(409);
        $this->actingAs($user)->postJson("/api/v1/creations/{$failed->public_id}/rating", ['rating' => -1])->assertStatus(409);
    }

    public function test_admin_list_shows_and_filters_ratings(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $this->poster($user, [], ['rating' => -1, 'rated_at' => now(), 'feedback' => 'Текст жижиг']);
        $this->poster($user, [], ['rating' => 1, 'rated_at' => now()]);
        $this->poster($user);

        $this->actingAs($admin)->getJson('/api/v1/admin/creations?rated=disliked')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.feedback', 'Текст жижиг')->assertJsonPath('data.0.rating', -1);
        $this->actingAs($admin)->getJson('/api/v1/admin/creations?rated=liked')->assertJsonCount(1, 'data');
        $this->actingAs($admin)->getJson('/api/v1/admin/creations')->assertJsonCount(3, 'data');
    }

    public function test_design_memory_is_null_with_too_little_data(): void
    {
        $user = User::factory()->create();
        $memory = new DesignMemory;
        $this->assertNull($memory->brief($user->id));

        $this->poster($user, ['map' => 'split', 'style' => 'Editorial Warmth'], ['rating' => 1, 'rated_at' => now()]);
        $this->poster($user, ['map' => 'split', 'style' => 'Editorial Warmth'], ['rating' => 1, 'rated_at' => now()]);
        // Үнэлээгүй болон reel-ийг тоолохгүй.
        $this->poster($user, ['map' => 'hero', 'style' => 'Swiss'], []);
        Creation::create(['user_id' => $user->id, 'type' => Creation::REEL, 'prompt' => 'x', 'status' => Creation::DONE, 'rating' => 1, 'rated_at' => now()]);

        $this->assertNull($memory->brief($user->id));
        $this->assertNull($memory->brief(null));
    }

    public function test_design_memory_aggregates_client_and_global_ratings(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        foreach (range(1, 3) as $i) {
            $this->poster($user, ['map' => 'split', 'style' => 'Editorial Warmth', 'palette' => 'cream + rust', 'type' => 'Playfair display', 'idea' => "idea {$i}"], ['rating' => 1, 'rated_at' => now()]);
        }
        $this->poster($user, ['map' => 'full-bleed', 'style' => 'Neon Night'], ['rating' => -1, 'rated_at' => now(), 'feedback' => 'Хэт бараан, текст уншигдахгүй']);
        // Бусад хэрэглэгчийн үнэлгээ нийт статистикт л орно.
        $this->poster($other, ['map' => 'full-bleed', 'style' => 'Neon Night'], ['rating' => -1, 'rated_at' => now(), 'feedback' => 'Нууц санал']);
        $this->poster($other, ['map' => 'split', 'style' => 'Swiss Grid'], ['rating' => 1, 'rated_at' => now()]);

        $brief = (new DesignMemory)->brief($user->id);

        $this->assertNotNull($brief);
        $this->assertStringContainsString('Liked by this client: split + Editorial Warmth (3×)', $brief);
        $this->assertStringContainsString('cream + rust (3×)', $brief);
        $this->assertStringContainsString('Disliked by this client: full-bleed + Neon Night', $brief);
        $this->assertStringContainsString("'Хэт бараан, текст уншигдахгүй'", $brief);
        $this->assertStringNotContainsString('Нууц санал', $brief);
        $this->assertStringContainsString('best-rated maps split (+4 of 4)', $brief);
        $this->assertStringContainsString('worst-rated maps full-bleed (-2 of 2)', $brief);
        $this->assertStringContainsString('best-rated styles Editorial Warmth (+3 of 3)', $brief);
        // Эхний (алдаатай) deliver_poster-ийн design-ыг үл тооно.
        $this->assertStringNotContainsString('hero', $brief);

        // Шинэ хэрэглэгч: зөвхөн нийт статистик.
        $fresh = (new DesignMemory)->brief(User::factory()->create()->id);
        $this->assertStringNotContainsString('this client', explode("\n", $fresh)[1]);
        $this->assertStringContainsString('Across all clients', $fresh);
    }
}
