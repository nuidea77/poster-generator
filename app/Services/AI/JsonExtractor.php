<?php

namespace App\Services\AI;

use App\Services\AI\Exceptions\AiException;

class JsonExtractor
{
    /**
     * Pull the first JSON object out of a model response, tolerating
     * markdown code fences and leading/trailing chatter.
     *
     * @return array<string, mixed>
     */
    public static function decode(string $text): array
    {
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text);

        $decoded = json_decode($text, true);

        if (! is_array($decoded)) {
            $start = strpos($text, '{');
            $end = strrpos($text, '}');

            if ($start !== false && $end !== false && $end > $start) {
                $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
            }
        }

        if (! is_array($decoded)) {
            throw new AiException('AI returned a response that is not valid JSON.');
        }

        return $decoded;
    }
}
