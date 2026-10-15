<?php

namespace Tests\Feature;

use App\Models\StyleReference;
use App\Models\User;
use App\Services\Agent\ReferenceLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReferenceLibraryTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();

        return $admin;
    }

    // Өгөгдсөн хэмжээтэй жинхэнэ JPEG үүсгэнэ (GD).
    private function jpeg(string $name, int $w, int $h): UploadedFile
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 80, 40));
        ob_start();
        imagejpeg($img, null, 85);

        return UploadedFile::fake()->createWithContent($name, (string) ob_get_clean());
    }

    public function test_only_admins_can_use_the_library_api(): void
    {
        $this->getJson('/api/v1/admin/references')->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson('/api/v1/admin/references')->assertForbidden();
        $this->actingAs(User::factory()->create())
            ->post('/api/v1/admin/references', ['category' => 'food', 'images' => [$this->jpeg('a.jpg', 100, 100)]], ['Accept' => 'application/json'])
            ->assertForbidden();
        $this->assertSame(0, StyleReference::count());
    }

    public function test_upload_downscales_and_shares_fields_across_files(): void
    {
        Storage::fake('public');

        $res = $this->actingAs($this->admin())->post('/api/v1/admin/references', [
            'images' => [$this->jpeg('diag-band_dish.jpg', 3000, 2000), $this->jpeg('small.jpg', 800, 600)],
            'category' => 'food',
            'style' => 'Editorial Warmth',
            'notes' => 'diagonal colour band behind the dish',
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonCount(2);

        [$big, $small] = StyleReference::orderBy('id')->get();
        $this->assertSame('diag band dish', $big->title);
        $this->assertSame('Editorial Warmth', $small->style);
        $this->assertSame('diagonal colour band behind the dish', $small->notes);
        $this->assertTrue($big->enabled);
        $this->assertSame('/storage/'.$big->path, $res->json('0.url'));

        Storage::disk('public')->assertExists($big->path);
        [$w, $h] = getimagesizefromstring(Storage::disk('public')->get($big->path));
        $this->assertSame([1536, 1024], [$w, $h]);
        [$w, $h] = getimagesizefromstring(Storage::disk('public')->get($small->path));
        $this->assertSame([800, 600], [$w, $h]); // жижиг зургийг хэвээр нь
    }

    public function test_upload_validation(): void
    {
        Storage::fake('public');
        $admin = $this->admin();

        $this->actingAs($admin)->post('/api/v1/admin/references', ['category' => 'cars', 'images' => [$this->jpeg('a.jpg', 50, 50)]], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('category');
        $this->actingAs($admin)->post('/api/v1/admin/references', ['category' => 'food', 'images' => [UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')]], ['Accept' => 'application/json'])
            ->assertUnprocessable();
        $this->actingAs($admin)->post('/api/v1/admin/references', ['category' => 'food', 'images' => [UploadedFile::fake()->create('big.jpg', 9000, 'image/jpeg')]], ['Accept' => 'application/json'])
            ->assertUnprocessable();
        $this->assertSame(0, StyleReference::count());
    }

    public function test_list_filter_update_toggle_and_delete(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('references/x.jpg', 'x');
        $food = StyleReference::create(['title' => 'Dish', 'category' => 'food', 'path' => 'references/x.jpg']);
        StyleReference::create(['title' => 'Lipstick', 'category' => 'beauty', 'path' => 'references/y.jpg']);
        $admin = $this->admin();

        $this->actingAs($admin)->getJson('/api/v1/admin/references')->assertOk()->assertJsonCount(2);
        $this->actingAs($admin)->getJson('/api/v1/admin/references?category=food')->assertOk()
            ->assertJsonCount(1)->assertJsonPath('0.title', 'Dish');

        $this->actingAs($admin)->putJson("/api/v1/admin/references/{$food->id}", ['notes' => 'warm side light', 'style' => 'Swiss', 'tags' => ['dish', 'warm']])
            ->assertOk()->assertJsonPath('notes', 'warm side light')->assertJsonPath('tags.1', 'warm');
        $this->assertSame('food', $food->fresh()->category);

        $this->actingAs($admin)->postJson("/api/v1/admin/references/{$food->id}/toggle")->assertOk()->assertJsonPath('enabled', false);
        $this->assertFalse($food->fresh()->enabled);
        $this->actingAs($admin)->postJson("/api/v1/admin/references/{$food->id}/toggle")->assertJsonPath('enabled', true);

        $this->actingAs($admin)->deleteJson("/api/v1/admin/references/{$food->id}")->assertNoContent();
        $this->assertNull(StyleReference::find($food->id));
        Storage::disk('public')->assertMissing('references/x.jpg');
    }

    public function test_search_ranks_style_matches_first_and_skips_disabled(): void
    {
        $old = StyleReference::create(['title' => 'Old swiss', 'category' => 'food', 'style' => 'Swiss', 'path' => 'r/1.jpg']);
        $partial = StyleReference::create(['title' => 'Neo', 'category' => 'food', 'style' => 'Neo Swiss Grid', 'path' => 'r/2.jpg']);
        $newest = StyleReference::create(['title' => 'Warm', 'category' => 'food', 'style' => 'Editorial Warmth', 'notes' => 'side light', 'path' => 'r/3.jpg']);
        StyleReference::create(['title' => 'Off', 'category' => 'food', 'style' => 'Swiss', 'path' => 'r/4.jpg', 'enabled' => false]);
        StyleReference::create(['title' => 'Beauty', 'category' => 'beauty', 'style' => 'Swiss', 'path' => 'r/5.jpg']);
        $library = app(ReferenceLibrary::class);

        $ids = array_column($library->search('food', 'swiss'), 'id');
        $this->assertSame(["ref_{$old->id}", "ref_{$partial->id}", "ref_{$newest->id}"], $ids);

        // Хэв маяггүй бол шинэ нь эхэндээ; limit ажиллана.
        $this->assertSame(["ref_{$newest->id}", "ref_{$partial->id}"], array_column($library->search('food', null, 2), 'id'));
        $this->assertCount(4, $library->search());

        $this->assertSame([
            'id' => "ref_{$newest->id}", 'title' => 'Warm', 'category' => 'food',
            'style' => 'Editorial Warmth', 'notes' => 'side light', 'path' => 'r/3.jpg',
        ], $library->get("ref_{$newest->id}"));
        $this->assertNull($library->get('ref_999999'));
        $this->assertNull($library->get((string) $old->id));
        $disabled = StyleReference::where('enabled', false)->first();
        $this->assertNull($library->get("ref_{$disabled->id}"));
    }

    public function test_brief_list_puts_the_guessed_category_first_and_spreads_the_rest(): void
    {
        foreach (['food' => 20, 'beauty' => 5, 'tech' => 5, 'event' => 5] as $cat => $n) {
            for ($k = 0; $k < $n; $k++) {
                StyleReference::create(['title' => "$cat $k", 'category' => $cat, 'path' => "references/$cat-$k.jpg", 'enabled' => true]);
            }
        }

        $out = app(ReferenceLibrary::class)->forBrief('Бургер ресторан "Grill Nomad": шинэ бургер, хүргэлт', 7);
        $this->assertSame('food', $out['category']);
        $cats = array_column($out['refs'], 'category');
        $this->assertSame(array_fill(0, 14, 'food'), array_slice($cats, 0, 14)); // at most 14 of the guessed category
        $this->assertCount(24, $cats);
        $this->assertEqualsCanonicalizing(['beauty', 'tech', 'event'], array_values(array_unique(array_slice($cats, 14))));

        // No keyword: no category, just a spread.
        $this->assertNull(app(ReferenceLibrary::class)->forBrief('x')['category']);
    }
}
