<?php

/*
|--------------------------------------------------------------------------
| Costs, credit prices and per-job budgets
|--------------------------------------------------------------------------
|
| Prices are fixed in credits so customers know the cost up front. Each job
| gets a hard media budget so its API cost can never exceed price / 2 even in
| the worst case; typical jobs cost about a third of what they earn.
| See docs/PRICING.md for the calculation. Unit costs are list prices in USD.
|
*/

return [

    'usd_mnt' => (float) env('PRICING_USD_MNT', 3600),

    // Value of one credit in the cheapest paid plan (used for margin reports).
    'credit_mnt' => (int) env('PRICING_CREDIT_MNT', 1100),

    'costs' => [
        // Claude Fable, USD per 1M tokens.
        'claude' => [
            'input' => 10.0,
            'output' => 50.0,
            'cache_write' => 12.5,
            'cache_read' => 1.0,
        ],
        // USD per image (GPT Image high quality, portrait size; Gemini Flash Image).
        'image' => [
            'openai' => (float) env('COST_OPENAI_IMAGE', 0.25),
            'gemini' => (float) env('COST_GEMINI_IMAGE', 0.039),
        ],
        // USD per second of generated video (Seedance 1.0 Pro 1080p; Veo 3.1 Fast 1080p).
        'video_second' => [
            'seedance' => (float) env('COST_SEEDANCE_SECOND', 0.122),
            'veo' => (float) env('COST_VEO_SECOND', 0.12),
        ],
    ],

    // Credits charged when a job starts (refunded if it fails).
    'credits' => [
        'poster' => (int) env('CREDITS_POSTER', 12),            // first format
        'poster_extra_format' => (int) env('CREDITS_POSTER_EXTRA', 3),
        'reel' => (int) env('CREDITS_REEL', 55),
    ],

    // Hard cap on image + video spend per job (USD). The agent is told the
    // budget and generation tools refuse anything beyond it.
    'media_budget' => [
        'poster' => 0.50,                // ≈ 2 high-quality images
        'poster_extra_format' => 0.25,
        'reel' => 5.00,                  // ≈ 3 stills + 35 s of video
    ],

];
