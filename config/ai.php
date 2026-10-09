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

    ],

];
