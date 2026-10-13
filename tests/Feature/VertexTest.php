<?php

namespace Tests\Feature;

use App\Services\AI\AiManager;
use App\Services\AI\Vertex;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VertexTest extends TestCase
{
    private function serviceAccount(): string
    {
        // Windows PHP builds need an explicit openssl.cnf to create keys.
        $conf = collect([getenv('OPENSSL_CONF'), dirname(PHP_BINARY).'/extras/ssl/openssl.cnf'])->first(fn ($p) => $p && is_file($p));
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA] + ($conf ? ['config' => $conf] : []);
        $key = openssl_pkey_new($options) ?: $this->markTestSkipped('OpenSSL cannot create keys here');
        openssl_pkey_export($key, $pem, null, $conf ? ['config' => $conf] : []);

        return json_encode(['type' => 'service_account', 'project_id' => 'my-proj', 'client_email' => 'studio@my-proj.iam.gserviceaccount.com', 'private_key' => $pem, 'token_uri' => 'https://oauth2.googleapis.com/token']);
    }

    private function png(): string
    {
        $img = imagecreatetruecolor(8, 8);
        ob_start();
        imagepng($img);

        return ob_get_clean();
    }

    public function test_gemini_images_run_through_vertex_with_a_service_account(): void
    {
        config([
            'ai.providers.gemini.via' => 'vertex', 'ai.providers.gemini.key' => null,
            'ai.providers.gemini_pro.via' => 'vertex', 'ai.providers.gemini_pro.key' => null,
            'ai.vertex' => ['project' => null, 'location' => 'us-central1', 'image_location' => 'global', 'credentials' => $this->serviceAccount(), 'api_key' => null],
        ]);
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.tok', 'expires_in' => 3600]),
            'aiplatform.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode($this->png())]]]]]]]),
        ]);

        $ai = app(AiManager::class);
        $this->assertContains('gemini', $ai->available('image'));
        $image = $ai->image('gemini_pro')->generateImage('poster', '4:5');
        $ai->image('gemini')->generateImage('photo', '1:1');

        $this->assertSame('image/png', $image['mime']);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://oauth2.googleapis.com/token'
            && $r['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer' && substr_count($r['assertion'], '.') === 2);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://aiplatform.googleapis.com/v1/projects/my-proj/locations/global/publishers/google/models/gemini-3-pro-image:generateContent'
            && $r->hasHeader('Authorization', 'Bearer ya29.tok')
            && $r['generationConfig']['imageConfig'] === ['aspectRatio' => '4:5', 'imageSize' => '2K']);
        $this->assertCount(1, collect(Http::recorded())->filter(fn ($p) => str_contains($p[0]->url(), 'oauth2'))); // token cached
    }

    public function test_a_pinned_image_provider_is_the_only_one_offered_until_paused(): void
    {
        config(['ai.providers.gemini.key' => 'k', 'ai.providers.gemini_pro.key' => 'k', 'ai.providers.gemini_pro.via' => 'gemini_api', 'ai.only.image' => 'gemini_pro']);
        $ai = app(AiManager::class);

        $this->assertSame(['gemini_pro'], $ai->available('image'));
        $ai->noteFailure('gemini_pro', 'Your prepayment credits are depleted.');
        $this->assertContains('gemini', $ai->available('image'));
        $this->assertNotContains('gemini_pro', $ai->available('image'));
    }

    public function test_vertex_api_key_uses_express_mode_for_images_but_not_veo(): void
    {
        config([
            'ai.providers.gemini.via' => 'vertex', 'ai.providers.gemini.key' => null,
            'ai.providers.veo.via' => 'vertex', 'ai.providers.veo.key' => null,
            'ai.vertex' => ['project' => null, 'location' => 'us-central1', 'image_location' => 'global', 'credentials' => null, 'api_key' => 'AQ.vertex-key'],
        ]);
        Http::fake(['aiplatform.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode($this->png())]]]]]]])]);

        $ai = app(AiManager::class);
        $ai->image('gemini')->generateImage('photo', '1:1');

        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://aiplatform.googleapis.com/v1/publishers/google/models/gemini-2.5-flash-image:generateContent?key=AQ.vertex-key'));
        $this->assertNotContains('veo', $ai->available('video')); // Veo on Vertex needs a service account

        // With a project the key is pinned to the global endpoint (Google's own pick may lack the model).
        config(['ai.vertex.project' => 'my-proj']);
        app()->forgetInstance(AiManager::class);
        app(AiManager::class)->image('gemini')->generateImage('photo', '1:1');
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://aiplatform.googleapis.com/v1/projects/my-proj/locations/global/publishers/google/models/gemini-2.5-flash-image:generateContent?key=AQ.vertex-key'));
    }

    public function test_vertex_without_credentials_falls_back_to_the_gemini_api(): void
    {
        config([
            'ai.providers.gemini.via' => 'vertex', 'ai.providers.gemini.key' => 'k-gemini',
            'ai.vertex' => ['project' => null, 'location' => 'us-central1', 'image_location' => 'global', 'credentials' => 'storage/keys/missing.json', 'api_key' => null],
        ]);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode($this->png())]]]]]]])]);

        app(AiManager::class)->image('gemini')->generateImage('photo', '1:1');

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'generativelanguage.googleapis.com') && $r->hasHeader('x-goog-api-key', 'k-gemini'));
    }

    public function test_a_gemini_api_outage_does_not_pause_the_vertex_route(): void
    {
        config(['ai.providers.gemini.via' => 'gemini_api', 'ai.providers.gemini.key' => 'k-gemini']);
        $ai = app(AiManager::class);
        $ai->noteFailure('gemini', 'Your prepayment credits are depleted.');
        $this->assertTrue($ai->paused('gemini'));

        config(['ai.providers.gemini.via' => 'vertex', 'ai.vertex' => ['project' => null, 'location' => 'us-central1', 'image_location' => 'global', 'credentials' => $this->serviceAccount(), 'api_key' => null]]);
        $this->assertFalse($ai->paused('gemini'));
        $this->assertContains('gemini', $ai->available('image'));
    }

    public function test_relative_credentials_path_is_read_from_the_project_root(): void
    {
        $file = 'storage/framework/testing/vertex-test.json';
        File::ensureDirectoryExists(base_path(dirname($file)));
        file_put_contents(base_path($file), $this->serviceAccount());
        config(['ai.vertex' => ['project' => null, 'location' => 'us-central1', 'image_location' => 'global', 'credentials' => $file, 'api_key' => null]]);

        $this->assertTrue(Vertex::fromConfig()->ready(needsServiceAccount: true));
        @unlink(base_path($file));
    }

    public function test_veo_runs_through_vertex_and_returns_the_inline_clip(): void
    {
        config([
            'ai.providers.veo.via' => 'vertex', 'ai.providers.veo.key' => null,
            'ai.vertex' => ['project' => 'other-proj', 'location' => 'us-central1', 'image_location' => 'global', 'credentials' => $this->serviceAccount(), 'api_key' => null],
        ]);
        $base = 'https://us-central1-aiplatform.googleapis.com/v1/projects/other-proj/locations/us-central1/publishers/google/models/veo-3.1-generate-preview';
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.tok']),
            "{$base}:predictLongRunning" => Http::response(['name' => 'projects/other-proj/locations/us-central1/publishers/google/models/veo-3.1-generate-preview/operations/op-1']),
            "{$base}:fetchPredictOperation" => Http::sequence()
                ->push(['done' => false])
                ->push(['done' => true, 'response' => ['videos' => [['bytesBase64Encoded' => base64_encode('MP4DATA'), 'mimeType' => 'video/mp4']]]]),
        ]);

        $veo = app(AiManager::class)->video('veo');
        $op = $veo->submit('steam rises', '9:16', 6, ['data' => 'PNG', 'mime' => 'image/png']);

        $this->assertSame(['state' => 'pending'], $veo->status($op));
        $done = $veo->status($op);
        $this->assertSame('done', $done['state']);
        $this->assertSame(['data' => 'MP4DATA', 'mime' => 'video/mp4'], $veo->download($done['url']));

        Http::assertSent(fn (Request $r) => $r->url() === "{$base}:predictLongRunning"
            && $r['instances'][0]['image'] === ['bytesBase64Encoded' => base64_encode('PNG'), 'mimeType' => 'image/png']
            && $r['parameters'] === ['aspectRatio' => '9:16', 'durationSeconds' => 6, 'resolution' => '720p', 'sampleCount' => 1, 'generateAudio' => true]);
        Http::assertSent(fn (Request $r) => $r->url() === "{$base}:fetchPredictOperation" && $r['operationName'] === $op);
    }
}
