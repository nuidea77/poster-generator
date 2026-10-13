<?php

namespace App\Services\AI\Contracts;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * Split into building the request and reading the response so several images
 * can be requested at once in an Http::pool (CreativeAgent does that when
 * Fable asks for more than one image in a turn).
 */
interface ImageProvider
{
    /**
     * Generate an image and return its raw binary contents.
     *
     * @param  string  $aspect  One of CreativeAgent::ASPECTS ("1:1", "4:5", "16:9", …).
     * @param  array<int, array{data: string, mime: string}>  $references  Optional reference images (product, logo, style).
     * @return array{data: string, mime: string}
     */
    public function generateImage(string $prompt, string $aspect, array $references = []): array;

    /**
     * Send the generation request on $http (a plain client, or a pool slot).
     *
     * @param  array<int, array{data: string, mime: string}>  $references
     */
    public function imageRequest(PendingRequest $http, string $prompt, string $aspect, array $references = []): Response|PromiseInterface;

    /**
     * @return array{data: string, mime: string}
     */
    public function parseImage(Response $response): array;
}
