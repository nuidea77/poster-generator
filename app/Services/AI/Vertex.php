<?php

namespace App\Services\AI;

use App\Services\AI\Exceptions\AiException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Google Cloud Vertex AI: endpoints and auth shared by the Gemini image and
 * Veo providers when they run "via vertex".
 *
 * Auth: a service-account JSON key (VERTEX_CREDENTIALS, works for every model;
 * exchanged for an OAuth token, cached ~50 min) or a Vertex API key
 * (VERTEX_API_KEY, express mode — Gemini image models only).
 */
class Vertex
{
    public function __construct(private array $config) {}

    public static function fromConfig(): self
    {
        return new self(config('ai.vertex'));
    }

    public function ready(bool $needsServiceAccount = false): bool
    {
        return $this->credentials() !== null || (! $needsServiceAccount && filled($this->config['api_key'] ?? null));
    }

    /**
     * generateContent URL for a Gemini model (images run on the global endpoint).
     */
    public function generateContentUrl(string $model): string
    {
        if ($this->credentials() === null) {
            // With an API key the bare URL lets Google pick a region, which may lack the image
            // models (it routed to asia-southeast1); naming the project pins the global endpoint.
            return filled($this->config['project'] ?? null)
                ? "https://aiplatform.googleapis.com/v1/projects/{$this->config['project']}/locations/global/publishers/google/models/{$model}:generateContent"
                : "https://aiplatform.googleapis.com/v1/publishers/google/models/{$model}:generateContent";
        }

        $location = $this->config['image_location'] ?: 'global';

        return $this->host($location)."/v1/projects/{$this->project()}/locations/{$location}/publishers/google/models/{$model}:generateContent";
    }

    /** Regional model URL for Veo (predictLongRunning, fetchPredictOperation). */
    public function modelUrl(string $model, string $method): string
    {
        $location = $this->config['location'] ?: 'us-central1';

        return $this->host($location)."/v1/projects/{$this->project()}/locations/{$location}/publishers/google/models/{$model}:{$method}";
    }

    /**
     * Attach auth to a request: bearer token for a service account, ?key= for an API key.
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

    public function token(): string
    {
        $sa = $this->credentials() ?? throw new AiException('Vertex AI needs a service-account key (VERTEX_CREDENTIALS) for this model.');

        return Cache::remember('vertex:token:'.md5($sa['client_email']), now()->addMinutes(50), function () use ($sa) {
            $now = time();
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

            $response = Http::asForm()->post($sa['token_uri'] ?? 'https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $unsigned.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '='),
            ]);

            return $response->json('access_token') ?? throw new AiException('Vertex AI auth failed: '.($response->json('error_description') ?? $response->body()));
        });
    }

    /** @return array{client_email: string, private_key: string, project_id?: string, token_uri?: string}|null */
    private function credentials(): ?array
    {
        $value = $this->config['credentials'] ?? null;

        if (blank($value)) {
            return null;
        }

        $absolute = str_starts_with($value, '/') || str_starts_with($value, '\\') || preg_match('/^[a-zA-Z]:[\\\\\/]/', $value);
        $path = $absolute ? $value : base_path($value); // relative paths are from the project root
        $json = str_starts_with(trim($value), '{') ? $value : (is_file($path) ? file_get_contents($path) : null);
        $data = $json ? json_decode($json, true) : null;

        return isset($data['client_email'], $data['private_key']) ? $data : null;
    }

    private function project(): string
    {
        return $this->config['project'] ?: ($this->credentials()['project_id'] ?? throw new AiException('Vertex AI: set VERTEX_PROJECT.'));
    }

    private function host(string $location): string
    {
        return $location === 'global' ? 'https://aiplatform.googleapis.com' : "https://{$location}-aiplatform.googleapis.com";
    }
}
