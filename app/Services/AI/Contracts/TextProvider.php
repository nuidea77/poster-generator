<?php

namespace App\Services\AI\Contracts;

interface TextProvider
{
    /**
     * Ask the model for a JSON object and return it decoded.
     *
     * @return array<string, mixed>
     */
    public function generateJson(string $system, string $prompt): array;
}
