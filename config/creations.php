<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Poster formats
    |--------------------------------------------------------------------------
    |
    | `aspect` is what the image model is asked for; the delivered file is then
    | cover-cropped to exactly width × height.
    |
    */

    'poster_formats' => [
        'feed_portrait' => [
            'label' => 'Пост 4:5',
            'platforms' => 'Instagram · Facebook',
            'aspect' => '4:5',
            'width' => 1080,
            'height' => 1350,
        ],
        'feed_square' => [
            'label' => 'Квадрат 1:1',
            'platforms' => 'Instagram · Facebook',
            'aspect' => '1:1',
            'width' => 1080,
            'height' => 1080,
        ],
        'story' => [
            'label' => 'Story 9:16',
            'platforms' => 'Instagram · Facebook story',
            'aspect' => '9:16',
            'width' => 1080,
            'height' => 1920,
        ],
        'fb_landscape' => [
            'label' => 'Хэвтээ 1.91:1',
            'platforms' => 'Facebook линк · зар',
            'aspect' => '16:9',
            'width' => 1200,
            'height' => 628,
        ],
    ],

    'reel' => [
        'width' => 1080,
        'height' => 1920,
        'fps' => 30,
        // No fixed length: the reel is as long as the clips the agent delivers.
        // Cap at Instagram's reel limit.
        'max_seconds' => (int) env('REEL_MAX_SECONDS', 180),
    ],

    'uploads' => [
        'max_product_images' => 5,
        'max_kb' => 10240,
    ],

    /*
    |--------------------------------------------------------------------------
    | Fair use (plans are unlimited)
    |--------------------------------------------------------------------------
    */

    'max_active_per_user' => (int) env('CREATIONS_MAX_ACTIVE', 2),
    'daily_limit' => [
        'poster' => env('CREATIONS_DAILY_POSTERS') !== null ? (int) env('CREATIONS_DAILY_POSTERS') : null,
        'reel' => env('CREATIONS_DAILY_REELS') !== null ? (int) env('CREATIONS_DAILY_REELS') : null,
    ],

    'job_timeout' => (int) env('CREATIONS_JOB_TIMEOUT', 3600),

];
