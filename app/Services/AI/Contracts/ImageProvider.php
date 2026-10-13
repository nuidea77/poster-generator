<?php

namespace App\Services\AI\Contracts;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * Зураг үүсгэгчийн гэрээ. Хүсэлт бэлдэх ба хариу уншихыг тусад нь салгасан нь
 * хэд хэдэн зургийг Http::pool-оор нэг дор хүсэх боломж олгоно (Fable нэг
 * алхамд нэгээс олон зураг хүсэхэд CreativeAgent ингэж ажилладаг).
 */
interface ImageProvider
{
    /**
     * Зураг үүсгээд түүний түүхий binary агуулгыг буцаана.
     *
     * @param  string  $aspect  CreativeAgent::ASPECTS-ийн нэг ("1:1", "4:5", "16:9", …).
     * @param  array<int, array{data: string, mime: string}>  $references  Сонголтот лавлах зургууд (бүтээгдэхүүн, лого, хэв маяг).
     * @return array{data: string, mime: string}
     */
    public function generateImage(string $prompt, string $aspect, array $references = []): array;

    /**
     * Үүсгэх хүсэлтийг $http дээр илгээнэ (энгийн client эсвэл pool-ын нэг слот).
     *
     * @param  array<int, array{data: string, mime: string}>  $references
     */
    public function imageRequest(PendingRequest $http, string $prompt, string $aspect, array $references = []): Response|PromiseInterface;

    /**
     * Хариунаас зургийн өгөгдлийг гаргаж авна; алдаатай бол AiException шиднэ.
     *
     * @return array{data: string, mime: string}
     */
    public function parseImage(Response $response): array;
}
