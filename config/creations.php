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
        'auto' => ['label' => 'Auto', 'width' => null, 'height' => null],
        '1:1' => ['label' => '1:1', 'width' => 1080, 'height' => 1080],
        '3:4' => ['label' => '3:4', 'width' => 1080, 'height' => 1440],
        '4:3' => ['label' => '4:3', 'width' => 1440, 'height' => 1080],
        '2:3' => ['label' => '2:3', 'width' => 1080, 'height' => 1620],
        '3:2' => ['label' => '3:2', 'width' => 1620, 'height' => 1080],
        '9:16' => ['label' => '9:16', 'width' => 1080, 'height' => 1920],
        '16:9' => ['label' => '16:9', 'width' => 1920, 'height' => 1080],
        '5:4' => ['label' => '5:4', 'width' => 1350, 'height' => 1080],
        '4:5' => ['label' => '4:5', 'width' => 1080, 'height' => 1350],
        '21:9' => ['label' => '21:9', 'width' => 2520, 'height' => 1080],
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
