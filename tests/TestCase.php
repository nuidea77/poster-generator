<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Tests never reach real APIs, whatever the local .env says.
        Http::preventStrayRequests();
        config([
            'ai.providers.gemini.via' => 'gemini_api',
            'ai.providers.gemini_pro.via' => 'gemini_api',
            'ai.providers.veo.via' => 'gemini_api',
            'ai.vertex' => ['project' => null, 'location' => 'us-central1', 'image_location' => 'global', 'credentials' => null, 'api_key' => null],
        ]);
    }
}
