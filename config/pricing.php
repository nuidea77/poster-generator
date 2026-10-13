<?php

/*
|--------------------------------------------------------------------------
| Costs, credit prices and per-job budgets
|--------------------------------------------------------------------------
|
| Prices are fixed in credits so customers know the cost up front. CostMeter
| computes them from the unit costs (list prices in USD) and the estimates
| below so that even with the most expensive models a job's API cost stays
| under price / 2 in the worst case and about price / 3 typically; each job
| has a hard media budget. See docs/PRICING.md.
|
*/

return [

    'usd_mnt' => (float) env('PRICING_USD_MNT', 3600),

    // Value of one credit in the cheapest paid plan: credit prices are set from
    // it and margin reports use it.
    'credit_mnt' => (int) env('PRICING_CREDIT_MNT', 1112),

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
            'gemini_pro' => (float) env('COST_GEMINI_PRO_IMAGE', 0.134), // Nano Banana Pro, 1K–2K
        ],
        // USD per second of generated video (Seedance 1.0 Pro 1080p; Veo 3.1 standard 720p/1080p).
        'video_second' => [
            'seedance' => (float) env('COST_SEEDANCE_SECOND', 0.122),
            'veo' => (float) env('COST_VEO_SECOND', 0.40),
        ],
    ],

    /*
    | Credit prices and media budgets are NOT typed in: CostMeter derives them
    | from the unit costs above, priced as if every image were the priciest
    | image model and every video second the priciest video model.
    |
    |   credits = ceil(max(worst × margin.worst, typical × margin.typical) / credit_mnt)
    |   media budget (hard cap per job) = worst images × image + worst seconds × video
    |
    | `php artisan pricing:report` prints the table and the real costs CostMeter
    | recorded on finished jobs.
    */
    'margin' => [
        'worst' => 2.0,
        'typical' => 3.0,
    ],

    // What one job uses. Fable as token counts (priced by CostMeter::claude),
    // media as image count and video seconds (× the reel length).
    'estimates' => [
        'poster' => [
            'typical' => ['claude' => ['input_tokens' => 30000, 'output_tokens' => 6000], 'images' => 1],
            'worst' => ['claude' => ['input_tokens' => 50000, 'output_tokens' => 10000], 'images' => 3], // 1 + 2 retries
        ],
        // Each further size is derived from the first image.
        'poster_extra_format' => [
            'typical' => ['claude' => ['input_tokens' => 2000, 'output_tokens' => 500], 'images' => 1],
            'worst' => ['claude' => ['input_tokens' => 8000, 'output_tokens' => 2000], 'images' => 1],
        ],
        // Fable tokens grow with the storyboard: fixed part + per second of reel.
        'reel' => [
            'typical' => [
                'claude' => ['input_tokens' => 40000, 'output_tokens' => 8000],
                'claude_per_second' => ['input_tokens' => 1000, 'output_tokens' => 300],
                'images' => 3,
                'video_factor' => 1.0,
            ],
            'worst' => [
                'claude' => ['input_tokens' => 60000, 'output_tokens' => 12000],
                'claude_per_second' => ['input_tokens' => 1500, 'output_tokens' => 450],
                'images' => 4,
                'video_factor' => 1.3, // 30% of the length re-rendered for failed clips
            ],
        ],
    ],

];
