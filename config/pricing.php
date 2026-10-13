<?php

/*
|--------------------------------------------------------------------------
| Өртөг, credit үнэ ба job тус бүрийн төсөв
|--------------------------------------------------------------------------
|
| Үнийг credit-ээр тогтмол тогтоосон тул хэрэглэгч өртгөө урьдчилан мэднэ.
| CostMeter үүнийг нэгж өртөг (USD жагсаалтын үнэ) ба доорх тооцооллоос
| гаргадаг: хамгийн үнэтэй загвартай байсан ч job-ийн API өртөг хамгийн муу
| үед үнэ / 2-оос, ердийн үед ойролцоогоор үнэ / 3-аас хэтрэхгүй; job бүр
| хатуу media төсөвтэй. docs/PRICING.md-г үз.
|
*/

return [

    // USD-ийн төгрөгийн ханш.
    'usd_mnt' => (float) env('PRICING_USD_MNT', 3600),

    // Хамгийн хямд төлбөртэй багц дахь нэг credit-ийн үнэ цэнэ: credit үнийг
    // үүнээс тогтоож, margin-ийн тайланд хэрэглэнэ.
    'credit_mnt' => (int) env('PRICING_CREDIT_MNT', 1112),

    // Нэгж өртөг (provider-уудын жагсаалтын үнэ, USD).
    'costs' => [
        // Claude Fable, 1M token тутмын USD.
        'claude' => [
            'input' => 10.0,
            'output' => 50.0,
            'cache_write' => 12.5,
            'cache_read' => 1.0,
        ],
        // Нэг зургийн USD (GPT Image high quality, босоо хэмжээ; Gemini Flash Image).
        'image' => [
            'openai' => (float) env('COST_OPENAI_IMAGE', 0.25),
            'gemini' => (float) env('COST_GEMINI_IMAGE', 0.039),
            'gemini_pro' => (float) env('COST_GEMINI_PRO_IMAGE', 0.134), // Nano Banana Pro, 1K–2K
        ],
        // Үүсгэсэн видеоны секунд тутмын USD (Seedance 1.0 Pro 1080p; Veo 3.1 standard 720p/1080p).
        'video_second' => [
            'seedance' => (float) env('COST_SEEDANCE_SECOND', 0.122),
            'veo' => (float) env('COST_VEO_SECOND', 0.40),
        ],
    ],

    /*
    | Credit үнэ ба media төсвийг гараар бичдэггүй: CostMeter дээрх нэгж
    | өртгөөс гаргана, зураг бүрийг хамгийн үнэтэй image загвараар, видеоны
    | секунд бүрийг хамгийн үнэтэй video загвараар тооцно.
    |
    |   credits = ceil(max(worst × margin.worst, typical × margin.typical) / credit_mnt)
    |   media төсөв (job тутмын хатуу дээд хязгаар) = worst images × image + worst seconds × video
    |
    | `php artisan pricing:report` хүснэгт болон дууссан job-ууд дээр CostMeter-ийн
    | бүртгэсэн бодит өртгийг хэвлэнэ.
    */
    // Өртгийн дээрх үржүүлэгч: хамгийн муу үед ≥2×, ердийн үед ≥3×.
    'margin' => [
        'worst' => 2.0,
        'typical' => 3.0,
    ],

    // Нэг job-ийн хэрэглээ. Fable-ийг token-ий тоогоор (CostMeter::claude үнэлнэ),
    // media-г зургийн тоо ба видеоны секундээр (× reel-ийн урт).
    'estimates' => [
        // Нэг постер (эхний хэмжээ).
        'poster' => [
            'typical' => ['claude' => ['input_tokens' => 30000, 'output_tokens' => 6000], 'images' => 1],
            'worst' => ['claude' => ['input_tokens' => 50000, 'output_tokens' => 10000], 'images' => 3], // 1 + 2 давталт
        ],
        // Нэмэлт хэмжээ бүрийг эхний зургаас гаргана.
        'poster_extra_format' => [
            'typical' => ['claude' => ['input_tokens' => 2000, 'output_tokens' => 500], 'images' => 1],
            'worst' => ['claude' => ['input_tokens' => 8000, 'output_tokens' => 2000], 'images' => 1],
        ],
        // Fable-ийн token storyboard-оо дагаж өснө: тогтмол хэсэг + reel-ийн секунд тутамд.
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
                'video_factor' => 1.3, // амжилтгүй клипийн улмаас уртын 30%-ийг дахин үүсгэнэ
            ],
        ],
    ],

];
