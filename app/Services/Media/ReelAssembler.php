<?php

namespace App\Services\Media;

use App\Services\AI\Exceptions\AiException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Builds the final reel: every clip is scaled/cropped to 1080×1920 @30fps,
 * concatenated in order (the reel is as long as the clips together), and
 * given a silent stereo AAC track so Instagram/Facebook accept the file.
 */
class ReelAssembler
{
    /**
     * @param  list<string>  $clipPaths  Absolute paths, in playback order.
     * @return string Absolute path of the finished MP4 (caller moves it).
     */
    public function assemble(array $clipPaths): string
    {
        if (! $clipPaths) {
            throw new AiException('No clips to assemble.');
        }

        ['width' => $w, 'height' => $h, 'fps' => $fps] = config('creations.reel');
        $ffmpeg = config('ai.ffmpeg');
        $dir = storage_path('app/private/reels/'.Str::uuid());
        File::ensureDirectoryExists($dir);

        try {
            $list = [];

            foreach (array_values($clipPaths) as $i => $clip) {
                $out = "{$dir}/n{$i}.mp4";
                $this->run([
                    $ffmpeg, '-y', '-i', $clip,
                    '-vf', "scale={$w}:{$h}:force_original_aspect_ratio=increase,crop={$w}:{$h},fps={$fps},setsar=1,format=yuv420p",
                    '-an', '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '19',
                    $out,
                ]);
                $list[] = "file '".str_replace("'", "'\\''", $out)."'";
            }

            File::put("{$dir}/list.txt", implode("\n", $list));

            $joined = "{$dir}/joined.mp4";
            $this->run([$ffmpeg, '-y', '-f', 'concat', '-safe', '0', '-i', "{$dir}/list.txt", '-c', 'copy', $joined]);

            $final = "{$dir}/reel.mp4";
            $this->run([
                $ffmpeg, '-y', '-i', $joined,
                '-f', 'lavfi', '-i', 'anullsrc=channel_layout=stereo:sample_rate=48000',
                '-map', '0:v', '-map', '1:a', '-shortest',
                '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '19', '-pix_fmt', 'yuv420p', '-r', (string) $fps,
                '-c:a', 'aac', '-b:a', '128k',
                '-movflags', '+faststart',
                $final,
            ]);

            $keep = storage_path('app/private/reels/'.Str::uuid().'.mp4');
            File::move($final, $keep);

            return $keep;
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /**
     * JPEG frame from the reel for library tiles and the video poster.
     */
    public function thumbnail(string $video, float $at = 2.0): string
    {
        $out = storage_path('app/private/reels/'.Str::uuid().'.jpg');
        $this->run([config('ai.ffmpeg'), '-y', '-ss', (string) $at, '-i', $video, '-frames:v', '1', '-vf', 'scale=540:-2', '-q:v', '3', $out]);
        $data = File::get($out);
        File::delete($out);

        return $data;
    }

    public function duration(string $path): float
    {
        $result = Process::timeout(60)->run([
            config('ai.ffprobe'), '-v', 'error', '-show_entries', 'format=duration', '-of', 'default=nw=1:nk=1', $path,
        ]);

        return (float) trim($result->output());
    }

    private function run(array $command): void
    {
        $result = Process::timeout(900)->run($command);

        if ($result->failed()) {
            throw new AiException('ffmpeg failed: '.Str::limit(trim($result->errorOutput()), 400));
        }
    }
}
