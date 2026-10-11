<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Poster formats
    |--------------------------------------------------------------------------
    |
    | Keyed by aspect ratio: the image model is asked for that aspect and the
    | delivered file is cover-cropped to exactly width × height (short side
    | 1080 px). `auto` lets the agent pick the ratio that suits the brief; the
    | poster then takes the size of the ratio it was generated at.
    |
    */

    'poster_formats' => [
        'auto' => ['label' => 'Auto', 'hint' => 'Брифэд тохирохыг автоматаар сонгоно', 'width' => null, 'height' => null],
        '1:1' => ['label' => '1:1', 'hint' => 'Квадрат пост · профайл', 'width' => 1080, 'height' => 1080],
        '3:4' => ['label' => '3:4', 'hint' => 'Instagram пост (босоо)', 'width' => 1080, 'height' => 1440],
        '4:3' => ['label' => '4:3', 'hint' => 'Хэвтээ пост', 'width' => 1440, 'height' => 1080],
        '2:3' => ['label' => '2:3', 'hint' => 'Постер · Pinterest', 'width' => 1080, 'height' => 1620],
        '3:2' => ['label' => '3:2', 'hint' => 'Хэвтээ зураг · баннер', 'width' => 1620, 'height' => 1080],
        '9:16' => ['label' => '9:16', 'hint' => 'Story · Reels', 'width' => 1080, 'height' => 1920],
        '16:9' => ['label' => '16:9', 'hint' => 'Facebook cover · хэвтээ баннер', 'width' => 1920, 'height' => 1080],
        '5:4' => ['label' => '5:4', 'hint' => 'Хэвтээ пост', 'width' => 1350, 'height' => 1080],
        '4:5' => ['label' => '4:5', 'hint' => 'Instagram · Facebook пост', 'width' => 1080, 'height' => 1350],
        '21:9' => ['label' => '21:9', 'hint' => 'Өргөн баннер · cover', 'width' => 2520, 'height' => 1080],
    ],

    'reel' => [
        // Video orientation; the first one is the default.
        'formats' => [
            '9:16' => ['label' => '9:16', 'hint' => 'Reels · Story · TikTok', 'width' => 1080, 'height' => 1920],
            '16:9' => ['label' => '16:9', 'hint' => 'YouTube · Facebook видео', 'width' => 1920, 'height' => 1080],
        ],
        'fps' => 30,
        // No fixed length: the reel is as long as the clips the agent delivers.
        // Cap at Instagram's reel limit.
        'max_seconds' => (int) env('REEL_MAX_SECONDS', 180),
    ],

    'uploads' => [
        'max_product_images' => 5,
        // Example images the customer wants the result to look like.
        'max_reference_images' => 3,
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
