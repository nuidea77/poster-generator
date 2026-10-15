<?php

namespace Tests\Feature;

use App\Models\Creation;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BrandKitTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Queue::fake();
        config(['creations.max_active_per_user' => 10, 'creations.daily_limit.poster' => null]); // queued jobs never finish here
        $this->seed(PlanSeeder::class);
        $this->user = User::factory()->create();
        Subscription::create(['user_id' => $this->user->id, 'plan_id' => Plan::where('slug', 'standard')->first()->id, 'starts_at' => now(), 'ends_at' => now()->addMonth(), 'credits' => 1000]);
    }

    public function test_brand_fields_are_updated_and_normalised(): void
    {
        $this->actingAs($this->user)->putJson('/api/v1/me/brand', [
            'brand_name' => 'Nomad Coffee',
            'brand_colors' => ['#1e3a8a', 'F59E0B', '#1E3A8A'],
            'brand_fonts' => 'bold modern sans',
            'brand_tone' => 'залуулаг',
            'brand_notes' => 'Слоган: Өглөө бүр шинэ.',
        ])
            ->assertOk()
            ->assertJsonPath('data.brand_name', 'Nomad Coffee')
            ->assertJsonPath('data.brand_colors', ['1E3A8A', 'F59E0B'])
            ->assertJsonPath('data.brand_fonts', 'bold modern sans')
            ->assertJsonPath('data.brand_tone', 'залуулаг')
            ->assertJsonPath('data.brand_notes', 'Слоган: Өглөө бүр шинэ.')
            ->assertJsonPath('data.brand_logo_url', null);

        // Only the sent fields change; an empty value clears one.
        $this->actingAs($this->user)->putJson('/api/v1/me/brand', ['brand_colors' => [], 'brand_tone' => ''])
            ->assertOk()
            ->assertJsonPath('data.brand_name', 'Nomad Coffee')
            ->assertJsonPath('data.brand_colors', [])
            ->assertJsonPath('data.brand_tone', null)
            ->assertJsonPath('data.brand_fonts', 'bold modern sans');

        $this->actingAs($this->user)->getJson('/api/v1/me')->assertJsonPath('data.brand_fonts', 'bold modern sans');
    }

    public function test_brand_validation_errors(): void
    {
        $this->putJson('/api/v1/me/brand', ['brand_name' => 'X'])->assertUnauthorized();

        $this->actingAs($this->user)->putJson('/api/v1/me/brand', ['brand_colors' => ['#12345', 'red']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['brand_colors.0', 'brand_colors.1']);

        $this->actingAs($this->user)->putJson('/api/v1/me/brand', ['brand_colors' => array_fill(0, 6, '#000000')])
            ->assertStatus(422)->assertJsonValidationErrors('brand_colors');

        $this->actingAs($this->user)->putJson('/api/v1/me/brand', ['brand_notes' => str_repeat('а', 1001)])
            ->assertStatus(422)->assertJsonValidationErrors('brand_notes');

        $this->actingAs($this->user)->post('/api/v1/me/brand/logo', ['logo' => UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('logo');

        $this->actingAs($this->user)->postJson('/api/v1/me/brand/logo', [])
            ->assertStatus(422)->assertJsonValidationErrors('logo');

        $this->assertNull($this->user->fresh()->brand_colors);
    }

    public function test_saved_logo_is_reused_in_new_creations(): void
    {
        $url = $this->actingAs($this->user)->post('/api/v1/me/brand/logo', ['logo' => UploadedFile::fake()->image('logo.png', 200, 200)], ['Accept' => 'application/json'])
            ->assertOk()
            ->json('data.brand_logo_url');

        $path = $this->user->fresh()->brand_logo_path;
        $this->assertSame('/storage/'.$path, $url);
        Storage::disk('public')->assertExists($path);

        // No logo uploaded for the job: the brand logo is copied in as the logo input.
        $poster = ['type' => 'poster', 'formats' => ['1:1'], 'prompt' => 'Кофены постер'];
        $id = $this->actingAs($this->user)->postJson('/api/v1/creations', $poster)->assertStatus(202)->json('data.id');
        $logo = Creation::where('public_id', $id)->first()->inputs[0];

        $this->assertSame(['id' => 'logo', 'kind' => 'image', 'role' => 'logo', 'source' => 'upload'], array_diff_key($logo, ['path' => 1]));
        $this->assertStringStartsWith("creations/{$id}/inputs/", $logo['path']);
        Storage::disk('public')->assertExists($logo['path']);

        // The customer removed the saved logo for this job.
        $id = $this->actingAs($this->user)->postJson('/api/v1/creations', $poster + ['use_saved_logo' => '0'])->assertStatus(202)->json('data.id');
        $this->assertSame([], Creation::where('public_id', $id)->first()->inputs);

        // Deleting the brand logo removes the file and stops the reuse.
        $this->actingAs($this->user)->deleteJson('/api/v1/me/brand/logo')->assertOk()->assertJsonPath('data.brand_logo_url', null);
        Storage::disk('public')->assertMissing($path);

        $id = $this->actingAs($this->user)->postJson('/api/v1/creations', $poster)->assertStatus(202)->json('data.id');
        $this->assertSame([], Creation::where('public_id', $id)->first()->inputs);
    }

    public function test_brand_brief(): void
    {
        $this->assertNull($this->user->brandBrief());

        $this->user->update([
            'brand_name' => 'Nomad Coffee',
            'brand_colors' => ['1E3A8A', 'F59E0B'],
            'brand_fonts' => 'bold modern sans',
            'brand_tone' => 'залуулаг',
            'brand_notes' => "Слоган: Өглөө бүр шинэ.\nУлаан өнгө бүү хэрэглэ.",
            'brand_logo_path' => 'logos/x.png',
        ]);

        $this->assertSame(
            'Brand kit — name: Nomad Coffee; colours: 1E3A8A, F59E0B; fonts: bold modern sans; tone: залуулаг; notes: Слоган: Өглөө бүр шинэ. Улаан өнгө бүү хэрэглэ.; a saved logo is attached as the logo input.',
            $this->user->brandBrief()
        );

        $this->user->update(['brand_name' => null, 'brand_colors' => null, 'brand_fonts' => null, 'brand_notes' => null, 'brand_logo_path' => null]);
        $this->assertSame('Brand kit — tone: залуулаг.', $this->user->fresh()->brandBrief());
    }
}
