<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default providers
    |--------------------------------------------------------------------------
    |
    | Text providers write the poster copy / reel script (JSON). Image
    | providers render the background visuals. "demo" works without any API
    | key so the UI can be tried out locally.
    |
    */

    'default_text' => env('AI_DEFAULT_TEXT_PROVIDER', 'anthropic'),
    'default_image' => env('AI_DEFAULT_IMAGE_PROVIDER', 'openai'),

    'timeout' => (int) env('AI_TIMEOUT', 180),

    // Optional: converts recorded reels to H.264 MP4 for Instagram/TikTok.
    'ffmpeg' => env('FFMPEG_PATH', 'ffmpeg'),

    /*
    |--------------------------------------------------------------------------
    | Creative agent (Claude Fable + tools)
    |--------------------------------------------------------------------------
    |
    | The agent reads the brief and attached images, then decides which
    | image / video model to call. Thinking depth is set via effort.
    |
    */

    'agent' => [
        'model' => env('AI_AGENT_MODEL', 'claude-fable-5-1'),
        'effort' => env('AI_AGENT_EFFORT', 'high'),
        'max_steps' => (int) env('AI_AGENT_MAX_STEPS', 24),
        'timeout' => (int) env('AI_AGENT_TIMEOUT', 900),
        // Server-side refusal fallback (Claude API only). Set to false on Bedrock/Vertex.
        'fallbacks' => (bool) env('AI_AGENT_FALLBACKS', true),
    ],

    'providers' => [

        'anthropic' => [
            'label' => 'Claude Fable',
            'key' => env('ANTHROPIC_API_KEY'),
            'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
            'text_model' => env('ANTHROPIC_MODEL', 'claude-fable-5-1'),
        ],

        'openai' => [
            'label' => 'OpenAI GPT',
            'key' => env('OPENAI_API_KEY'),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'text_model' => env('OPENAI_MODEL', 'gpt-4.1-mini'),
            'image_model' => env('OPENAI_IMAGE_MODEL', 'gpt-image-1'),
        ],

        'gemini' => [
            'label' => 'Google Gemini',
            'key' => env('GEMINI_API_KEY'),
            'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
            'text_model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
            'image_model' => env('GEMINI_IMAGE_MODEL', 'gemini-2.5-flash-image'),
        ],

        // ByteDance Seedance via BytePlus ModelArk (video generation).
        'seedance' => [
            'label' => 'Seedance (видео)',
            'key' => env('SEEDANCE_API_KEY'),
            'base_url' => env('SEEDANCE_BASE_URL', 'https://ark.ap-southeast.bytepluses.com/api/v3'),
            'video_model' => env('SEEDANCE_MODEL', 'seedance-1-0-pro-250528'),
            'poll_interval' => (int) env('SEEDANCE_POLL_INTERVAL', 5),
            'poll_timeout' => (int) env('SEEDANCE_POLL_TIMEOUT', 600),
        ],

    ],

];
