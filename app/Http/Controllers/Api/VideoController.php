<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class VideoController extends Controller
{
    public static function ffmpegAvailable(): bool
    {
        return Cache::remember('ffmpeg-available', 3600, fn () => Process::run([config('ai.ffmpeg'), '-version'])->successful());
    }

    /**
     * Re-encode a browser recording (WebM / VP9) into an H.264 MP4 that
     * Instagram, TikTok and iPhones accept.
     */
    public function convert(Request $request): BinaryFileResponse
    {
        $request->validate([
            'video' => ['required', 'file', 'max:204800'],
        ]);

        abort_unless(self::ffmpegAvailable(), 501, 'ffmpeg is not installed on the server.');

        $dir = storage_path('app/private/convert');
        @mkdir($dir, 0755, true);
        $input = $request->file('video')->move($dir, Str::uuid().'.in')->getPathname();
        $output = $dir.'/'.Str::uuid().'.mp4';

        $result = Process::timeout(600)->run([
            config('ai.ffmpeg'), '-y', '-i', $input,
            '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '20', '-pix_fmt', 'yuv420p', '-r', '30',
            '-c:a', 'aac', '-b:a', '160k',
            '-movflags', '+faststart',
            $output,
        ]);

        @unlink($input);

        abort_unless($result->successful(), 500, 'Video conversion failed.');

        return response()->download($output, 'reel.mp4', ['Content-Type' => 'video/mp4'])->deleteFileAfterSend();
    }
}
