<?php

return [

    'timeout' => (int) env('AI_TIMEOUT', 180),

    'ffmpeg' => env('FFMPEG_PATH', 'ffmpeg'),
    'ffprobe' => env('FFPROBE_PATH', 'ffprobe'),

    /*
    |--------------------------------------------------------------------------
    | Creative agent (Claude Fable + skills + tools)
    |--------------------------------------------------------------------------
    |
    | Fable reads the brief and attachments and decides which image / video
    | model to call. Users never see which model produced a result.
    |
    */

    'agent' => [
        'model' => env('AI_AGENT_MODEL', 'claude-fable-5-1'),
        'effort' => env('AI_AGENT_EFFORT', 'high'),
        'max_steps' => (int) env('AI_AGENT_MAX_STEPS', 30),
        'timeout' => (int) env('AI_AGENT_TIMEOUT', 900),
        // Server-side refusal fallback (Claude API only). Set false on Bedrock/Vertex.
        'fallbacks' => (bool) env('AI_AGENT_FALLBACKS', true),
    ],

    'providers' => [

        'anthropic' => [
            'key' => env('ANTHROPIC_API_KEY'),
            'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
        ],

        // Image models
        'openai' => [
            'kind' => 'image',
            'key' => env('OPENAI_API_KEY'),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'image_model' => env('OPENAI_IMAGE_MODEL', 'gpt-image-1'),
        ],

        'gemini' => [
            'kind' => 'image',
            'key' => env('GEMINI_API_KEY'),
            'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
            'image_model' => env('GEMINI_IMAGE_MODEL', 'gemini-2.5-flash-image'),
        ],

        // Video models — ByteDance Seedance via BytePlus ModelArk.
        'seedance' => [
            'kind' => 'video',
            'key' => env('SEEDANCE_API_KEY'),
            'base_url' => env('SEEDANCE_BASE_URL', 'https://ark.ap-southeast.bytepluses.com/api/v3'),
            'video_model' => env('SEEDANCE_MODEL', 'seedance-1-0-pro-250528'),
            'poll_interval' => (int) env('SEEDANCE_POLL_INTERVAL', 8),
            'poll_timeout' => (int) env('SEEDANCE_POLL_TIMEOUT', 1500),
        ],

    ],

];
