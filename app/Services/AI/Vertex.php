<?php

namespace App\Services\AI;

use App\Services\AI\Exceptions\AiException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Google Cloud Vertex AI: Gemini зураг ба Veo үйлчилгээ үзүүлэгчид "via vertex"
 * горимоор ажиллахад хуваалцдаг endpoint болон auth.
 *
 * Auth: service-account JSON key (VERTEX_CREDENTIALS, бүх загварт ажиллана;
 * OAuth token-оор солигдож ~50 мин cache-лэгдэнэ) эсвэл Vertex API key
 * (VERTEX_API_KEY, express горим — зөвхөн Gemini зургийн загварт).
 */
class Vertex
{
    public function __construct(private array $config) {}

    // config('ai.vertex')-ээс instance үүсгэнэ.
    public static function fromConfig(): self
    {
        return new self(config('ai.vertex'));
    }

    // Vertex ашиглахад бэлэн эсэх; $needsServiceAccount бол API key хангалтгүй.
    public function ready(bool $needsServiceAccount = false): bool
    {
        return $this->credentials() !== null || (! $needsServiceAccount && filled($this->config['api_key'] ?? null));
    }

    /**
     * Gemini загварын generateContent URL (зураг global endpoint дээр ажиллана).
     */
    public function generateContentUrl(string $model): string
    {
        if ($this->credentials() === null) {
            // API key-тэй үед төсөлгүй URL нь Google-д бүс сонгуулдаг бөгөөд тэр бүсэд зургийн
            // загвар байхгүй байж болно (asia-southeast1 руу чиглүүлж байсан); төслийг нэрлэвэл global endpoint тогтоно.
            return filled($this->config['project'] ?? null)
                ? "https://aiplatform.googleapis.com/v1/projects/{$this->config['project']}/locations/global/publishers/google/models/{$model}:generateContent"
                : "https://aiplatform.googleapis.com/v1/publishers/google/models/{$model}:generateContent";
        }

        $location = $this->config['image_location'] ?: 'global';

        return $this->host($location)."/v1/projects/{$this->project()}/locations/{$location}/publishers/google/models/{$model}:generateContent";
    }

    /** Veo-д зориулсан бүсийн загварын URL (predictLongRunning, fetchPredictOperation). */
    public function modelUrl(string $model, string $method): string
    {
        $location = $this->config['location'] ?: 'us-central1';

        return $this->host($location)."/v1/projects/{$this->project()}/locations/{$location}/publishers/google/models/{$model}:{$method}";
    }

    /**
     * Хүсэлтэд auth залгана: service account бол bearer token, API key бол ?key=.
     */
    public function authorize(PendingRequest $http): PendingRequest
    {
        if ($this->credentials() !== null) {
            return $http->withToken($this->token());
        }

        if (filled($this->config['api_key'] ?? null)) {
            return $http->withQueryParameters(['key' => $this->config['api_key']]);
        }

        throw new AiException('Vertex AI is not configured: set VERTEX_CREDENTIALS (service-account JSON) or VERTEX_API_KEY.');
    }

    /**
     * Service-account key-ээр JWT гарын үсэг зурж OAuth access token авна; 50 мин cache-лэнэ.
     */
    public function token(): string
    {
        $sa = $this->credentials() ?? throw new AiException('Vertex AI needs a service-account key (VERTEX_CREDENTIALS) for this model.');

        return Cache::remember('vertex:token:'.md5($sa['client_email']), now()->addMinutes(50), function () use ($sa) {
            $now = time();
            // JWT-ийн header ба claim хэсгийг base64url хэлбэрээр бэлдэнэ.
            $segment = fn (array $data) => rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=');
            $unsigned = $segment(['alg' => 'RS256', 'typ' => 'JWT']).'.'.$segment([
                'iss' => $sa['client_email'],
                'scope' => 'https://www.googleapis.com/auth/cloud-platform',
                'aud' => $sa['token_uri'] ?? 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600,
            ]);

            if (! openssl_sign($unsigned, $signature, $sa['private_key'], OPENSSL_ALGO_SHA256)) {
                throw new AiException('Vertex AI: could not sign with the service-account key.');
            }

            // Гарын үсэгтэй JWT-г token endpoint дээр access token-оор солино.
            $response = Http::asForm()->post($sa['token_uri'] ?? 'https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $unsigned.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '='),
            ]);

            return $response->json('access_token') ?? throw new AiException('Vertex AI auth failed: '.($response->json('error_description') ?? $response->body()));
        });
    }

    /**
     * Service-account credentials-ийг уншина: JSON мөр эсвэл файлын зам (абсолют / төслийн үндсээс).
     *
     * @return array{client_email: string, private_key: string, project_id?: string, token_uri?: string}|null
     */
    private function credentials(): ?array
    {
        $value = $this->config['credentials'] ?? null;

        if (blank($value)) {
            return null;
        }

        $absolute = str_starts_with($value, '/') || str_starts_with($value, '\\') || preg_match('/^[a-zA-Z]:[\\\\\/]/', $value);
        $path = $absolute ? $value : base_path($value); // харьцангуй зам төслийн үндсээс эхэлнэ
        $json = str_starts_with(trim($value), '{') ? $value : (is_file($path) ? file_get_contents($path) : null);
        $data = $json ? json_decode($json, true) : null;

        return isset($data['client_email'], $data['private_key']) ? $data : null;
    }

    // GCP төслийн id: VERTEX_PROJECT, эс бөгөөс service account-ын project_id.
    private function project(): string
    {
        return $this->config['project'] ?: ($this->credentials()['project_id'] ?? throw new AiException('Vertex AI: set VERTEX_PROJECT.'));
    }

    // Байршлаас хамаарах API host (global эсвэл бүсийн).
    private function host(string $location): string
    {
        return $location === 'global' ? 'https://aiplatform.googleapis.com' : "https://{$location}-aiplatform.googleapis.com";
    }
}
