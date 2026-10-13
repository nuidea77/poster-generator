<?php

namespace App\Services\AI\Providers;

use App\Services\AI\Contracts\VideoProvider;
use App\Services\AI\Exceptions\AiException;
use App\Services\AI\Vertex;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Google Veo-г Gemini API-аар ашиглана (Gemini зурагтай ижил key); 'via' = vertex бол Vertex AI-аар.
 * POST /models/{model}:predictLongRunning → {name}; GET /{name} → done, response.generateVideoResponse.generatedSamples[0].video.uri
 */
class VeoProvider implements VideoProvider
{
    public function __construct(private array $config, private int $timeout) {}

    // Veo-гийн дэмждэг клипийн уртууд (секунд).
    public function durations(): array
    {
        return [4, 6, 8];
    }

    // predictLongRunning даалгавар илгээж, operation-ийн нэрийг буцаана.
    public function submit(string $prompt, string $aspect, int $duration, ?array $firstFrame = null): string
    {
        $instance = ['prompt' => trim($prompt)];

        // Vertex маягийн image талбар: Veo predict endpoint `inlineData`-г хүлээж авдаггүй (баримт бичигт байгаа ч).
        if ($firstFrame) {
            $instance['image'] = ['bytesBase64Encoded' => base64_encode($firstFrame['data']), 'mimeType' => $firstFrame['mime']];
        }

        // 1080p зөвхөн 8 s клипэд гардаг; богино клипүүд 720p болно.
        $resolution = $duration === 8 ? $this->config['resolution'] : '720p';

        $parameters = [
            'aspectRatio' => $aspect === '16:9' ? '16:9' : '9:16',
            'durationSeconds' => $duration, // JSON тоо байх ёстой
            'resolution' => $resolution,
        ];

        // Vertex-ээр бол sampleCount ба дуу (generateAudio) нэмж илгээнэ.
        $response = $this->vertex()
            ? $this->vertexClient()->post($this->vertexUrl('predictLongRunning'), ['instances' => [$instance], 'parameters' => $parameters + ['sampleCount' => 1, 'generateAudio' => true]])
            : $this->client()->post("/models/{$this->config['video_model']}:predictLongRunning", ['instances' => [$instance], 'parameters' => $parameters]);

        if ($response->failed() || ! $response->json('name')) {
            throw new AiException('Veo: '.($response->json('error.message') ?? $response->body()));
        }

        return (string) $response->json('name');
    }

    // Operation-ийн төлөвийг шалгана (Vertex бол vertexStatus руу шилжүүлнэ).
    public function status(string $taskId): array
    {
        if ($this->vertex()) {
            return $this->vertexStatus($taskId);
        }

        $response = $this->client()->get('/'.ltrim($taskId, '/'));

        if ($response->failed() || ! $response->json('done')) {
            return ['state' => 'pending']; // түр зуурын алдаанд нийт хугацаа дуустал шалгасаар байна
        }

        if ($error = $response->json('error.message')) {
            return ['state' => 'failed', 'error' => $error];
        }

        $uri = $response->json('response.generateVideoResponse.generatedSamples.0.video.uri');

        // Видео ирээгүй бол RAI шүүлтүүрийн шалтгааныг алдаанд оруулна.
        if (! $uri) {
            $reasons = (array) $response->json('response.generateVideoResponse.raiMediaFilteredReasons', []);

            return ['state' => 'failed', 'error' => $reasons ? 'filtered: '.implode('; ', $reasons) : 'no video returned'];
        }

        return ['state' => 'done', 'url' => (string) $uri];
    }

    // Клипийг авна: data: URI, gs:// (Cloud Storage) эсвэл Gemini API-ийн URL.
    public function download(string $url): array
    {
        // Cloud Storage bucket тохируулаагүй бол Vertex клипийг inline (base64) буцаадаг.
        if (str_starts_with($url, 'data:')) {
            return ['data' => base64_decode(substr($url, strpos($url, ',') + 1)), 'mime' => 'video/mp4'];
        }

        // gs://bucket/object-ийг service-account token-оор Cloud Storage API-аас татна.
        if (str_starts_with($url, 'gs://')) {
            [$bucket, $object] = explode('/', substr($url, 5), 2);
            $video = Http::timeout($this->timeout)->withToken(Vertex::fromConfig()->token())
                ->get('https://storage.googleapis.com/storage/v1/b/'.$bucket.'/o/'.rawurlencode($object), ['alt' => 'media']);

            return $video->successful() ? ['data' => $video->body(), 'mime' => 'video/mp4'] : throw new AiException('Veo: could not download the clip from Cloud Storage.');
        }

        $video = Http::timeout($this->timeout)
            ->withHeaders(['x-goog-api-key' => $this->config['key']])
            ->get($url);

        if ($video->failed()) {
            throw new AiException('Veo: could not download the clip.');
        }

        return ['data' => $video->body(), 'mime' => 'video/mp4'];
    }

    // Төлөв шалгах хоорондын зай (config-оос).
    public function pollInterval(): int
    {
        return $this->config['poll_interval'];
    }

    // Төлөв шалгах нийт хугацааны хязгаар (config-оос).
    public function pollTimeout(): int
    {
        return $this->config['poll_timeout'];
    }

    // Vertex AI-аар ажиллах эсэх.
    private function vertex(): bool
    {
        return ($this->config['via'] ?? null) === 'vertex';
    }

    // Vertex-ийн OAuth token-той HTTP client.
    private function vertexClient(): PendingRequest
    {
        return Http::timeout($this->timeout)->withToken(Vertex::fromConfig()->token());
    }

    // Veo загварын Vertex URL (өгсөн method-той).
    private function vertexUrl(string $method): string
    {
        return Vertex::fromConfig()->modelUrl($this->config['video_model'], $method);
    }

    // Vertex-ийн fetchPredictOperation-аар төлөв шалгана; видео base64 эсвэл gcsUri-гаар ирнэ.
    private function vertexStatus(string $operation): array
    {
        $response = $this->vertexClient()->post($this->vertexUrl('fetchPredictOperation'), ['operationName' => $operation]);

        if ($response->failed() || ! $response->json('done')) {
            return ['state' => 'pending'];
        }

        if ($error = $response->json('error.message')) {
            return ['state' => 'failed', 'error' => $error];
        }

        $video = $response->json('response.videos.0') ?? [];

        if ($b64 = $video['bytesBase64Encoded'] ?? null) {
            return ['state' => 'done', 'url' => 'data:video/mp4;base64,'.$b64];
        }

        if ($uri = $video['gcsUri'] ?? null) {
            return ['state' => 'done', 'url' => $uri];
        }

        $filtered = (array) $response->json('response.raiMediaFilteredReasons', []);

        return ['state' => 'failed', 'error' => $filtered ? 'filtered: '.implode('; ', $filtered) : 'no video returned'];
    }

    // Gemini API-д зориулсан base URL ба API key-тэй HTTP client.
    private function client(): PendingRequest
    {
        return Http::timeout($this->timeout)
            ->baseUrl(rtrim($this->config['base_url'], '/'))
            ->withHeaders(['x-goog-api-key' => $this->config['key']]);
    }
}
