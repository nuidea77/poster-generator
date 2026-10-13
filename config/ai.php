<?php

return [

    // Provider-ийн HTTP хүсэлтийн timeout (секунд).
    'timeout' => (int) env('AI_TIMEOUT', 180),
    // Provider quota / credit дууссан гэж мэдэгдсэний дараа хэдэн минут түр зогсоох.
    'pause_minutes' => (int) env('AI_PAUSE_MINUTES', 30),

    // Төрөл тус бүрд нэг provider-ийг тогтоох (жишээ нь AI_IMAGE_ONLY=gemini_pro); бусад нь fallback.
    'only' => ['image' => env('AI_IMAGE_ONLY'), 'video' => env('AI_VIDEO_ONLY')],

    // Постер тайрах, reel угсрах, текст давхарлахад хэрэглэх ffmpeg / ffprobe-ийн зам.
    'ffmpeg' => env('FFMPEG_PATH', 'ffmpeg'),
    'ffprobe' => env('FFPROBE_PATH', 'ffprobe'),

    /*
    |--------------------------------------------------------------------------
    | Бүтээлч agent (Claude Fable + skills + tools)
    |--------------------------------------------------------------------------
    |
    | Fable брифф ба хавсралтыг уншаад аль image / video загварыг дуудахаа
    | шийднэ. Үр дүнг аль загвар гаргасныг хэрэглэгч хэзээ ч харахгүй.
    |
    */

    'agent' => [
        'model' => env('AI_AGENT_MODEL', 'claude-fable-5-1'),
        'effort' => env('AI_AGENT_EFFORT', 'medium'), // low | medium | high; medium нь хурдан, хямд, high нь хамгийн нямбай art direction
        // Нэг job-д agent-ийн хийх алхмын (tool дуудлагын эргэлт) дээд тоо.
        'max_steps' => (int) env('AI_AGENT_MAX_STEPS', 30),
        // Thinking үүнд тооцогдоно; тасарсан хариу сүүлийн tool call-оо дутуу үлдээнэ (зөвхөн хэрэглэсэн token-д төлбөр гарна).
        'max_tokens' => (int) env('AI_AGENT_MAX_TOKENS', 12000), // энгийн эргэлт < 4k хэрэглэнэ; тасарвал сэргээнэ
        // Нэг agent ажиллагааны нийт timeout (секунд).
        'timeout' => (int) env('AI_AGENT_TIMEOUT', 900),
        // Сервер талын refusal fallback (зөвхөн Claude API). Bedrock/Vertex дээр false болго.
        'fallbacks' => (bool) env('AI_AGENT_FALLBACKS', true),
        // Fable-ийг хаанаас дуудах: anthropic (Claude API) эсвэл vertex (Google Cloud, service account шаардана).
        'via' => env('AI_AGENT_VIA', 'anthropic'),
        // Vertex дээрх загварын нэр өөр байвал (жишээ нь хувилбарын дагавартай); хоосон бол `model`.
        'vertex_model' => env('AI_AGENT_VERTEX_MODEL'),
    ],

    // Гадны AI provider-ууд: `kind` нь image эсвэл video, agent аль нэгийг нь дуудлага бүрт сонгоно.
    'providers' => [

        // Claude API (CreativeAgent-ийн Fable).
        'anthropic' => [
            'key' => env('ANTHROPIC_API_KEY'),
            'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
        ],

        // Image загварууд. Gemini / Gemini Pro / Veo нь Gemini API (анхдагч) эсвэл
        // Google Cloud Vertex AI-аар ажиллана: GEMINI_VIA / VEO_VIA=vertex болгоод доорх `vertex`-ийг бөглө.
        // GPT Image (OpenAI).
        'openai' => [
            'kind' => 'image',
            'key' => env('OPENAI_API_KEY'),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'image_model' => env('OPENAI_IMAGE_MODEL', 'gpt-image-1'),
            'image_quality' => env('OPENAI_IMAGE_QUALITY', 'high'), // low | medium | high — COST_OPENAI_IMAGE-ийг үүнтэй нийцүүлж байх
        ],

        // Gemini Flash Image (хямд, хурдан).
        'gemini' => [
            'kind' => 'image',
            'key' => env('GEMINI_API_KEY'),
            'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
            'image_model' => env('GEMINI_IMAGE_MODEL', 'gemini-2.5-flash-image'),
            'via' => env('GEMINI_VIA', 'gemini_api'), // gemini_api | vertex
        ],

        // Nano Banana Pro: үсэглэл (монгол кирилл, үнэ) ба нарийн ширийн нь хамаагүй сайн,
        // илүү удаан, үнэ нь ~3× — typography болон hero зурагт.
        'gemini_pro' => [
            'kind' => 'image',
            'key' => env('GEMINI_PRO_API_KEY', env('GEMINI_API_KEY')),
            'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
            'image_model' => env('GEMINI_PRO_IMAGE_MODEL', 'gemini-3-pro-image-preview'),
            'vertex_image_model' => env('GEMINI_PRO_VERTEX_IMAGE_MODEL', 'gemini-3-pro-image'), // Vertex дээрх GA нэр
            'image_size' => env('GEMINI_PRO_IMAGE_SIZE', '2K'), // 1K | 2K (ижил үнэтэй) | 4K
            'via' => env('GEMINI_PRO_VIA', env('GEMINI_VIA', 'gemini_api')),
        ],

        // Video загварууд. Seedance: ByteDance, BytePlus ModelArk-аар дамжина.
        'seedance' => [
            'kind' => 'video',
            'key' => env('SEEDANCE_API_KEY'),
            'base_url' => env('SEEDANCE_BASE_URL', 'https://ark.ap-southeast.bytepluses.com/api/v3'),
            'video_model' => env('SEEDANCE_MODEL', 'seedance-1-0-pro-250528'),
            // Үр дүнг хэдэн секунд тутам шалгах, нийт хэдэн секунд хүлээх.
            'poll_interval' => (int) env('SEEDANCE_POLL_INTERVAL', 8),
            'poll_timeout' => (int) env('SEEDANCE_POLL_TIMEOUT', 1500),
        ],

        // Veo: Google, Gemini API-аар дамжина. VEO_API_KEY өгөөгүй бол Gemini key-г хэрэглэнэ.
        'veo' => [
            'kind' => 'video',
            'key' => env('VEO_API_KEY', env('GEMINI_API_KEY')),
            'base_url' => env('VEO_BASE_URL', env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta')),
            // Стандарт загвар ($0.40/s); credit үнийг үүгээр тооцсон. veo-3.1-fast-generate-preview
            // ~$0.12/s бөгөөд зөвхөн margin-ийг өсгөнө. COST_VEO_SECOND-ийг үүнтэй нийцүүлж байх.
            'video_model' => env('VEO_MODEL', 'veo-3.1-generate-preview'),
            'resolution' => env('VEO_RESOLUTION', '1080p'), // 720p | 1080p (1080p зөвхөн 8 s клипэд)
            'poll_interval' => (int) env('VEO_POLL_INTERVAL', 10),
            'poll_timeout' => (int) env('VEO_POLL_TIMEOUT', 1500),
            'via' => env('VEO_VIA', 'gemini_api'), // gemini_api | vertex (Vertex-д service account хэрэгтэй)
        ],

    ],

    // Google Cloud Vertex AI, via=vertex тохируулсан provider-уудад.
    'vertex' => [
        'project' => env('VERTEX_PROJECT'),                         // анхдагч: service account-ийн project
        'location' => env('VERTEX_LOCATION', 'us-central1'),        // Veo
        'image_location' => env('VERTEX_IMAGE_LOCATION', 'global'), // Gemini image загварууд
        'credentials' => env('VERTEX_CREDENTIALS'),                 // service-account JSON key-ийн зам (эсвэл JSON өөрөө)
        'api_key' => env('VERTEX_API_KEY'),                         // express mode, зөвхөн Gemini зураг
        'claude_location' => env('VERTEX_CLAUDE_LOCATION', 'global'), // Claude (Fable) загварын бүс
    ],

];
