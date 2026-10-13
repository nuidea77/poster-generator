<?php

namespace App\Services\Media;

use App\Services\AI\Exceptions\AiException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Builds the final reel: every clip is scaled/cropped to the reel size (9:16 or 16:9) @30fps,
 * concatenated in order (the reel is as long as the clips together). Each clip
 * keeps its own sound (Veo renders audio; silent clips get silence), and the
 * whole stereo AAC track is loudness-normalised so Instagram/Facebook accept it.
 */
class ReelAssembler
{
    /**
     * @param  list<string>  $clipPaths  Absolute paths, in playback order.
     * @return string Absolute path of the finished MP4 (caller moves it).
     */
    public function assemble(array $clipPaths, int $w, int $h): string
    {
        if (! $clipPaths) {
            throw new AiException('No clips to assemble.');
        }

        $fps = config('creations.reel.fps');
        $ffmpeg = config('ai.ffmpeg');
        $dir = storage_path('app/private/reels/'.Str::uuid());
        File::ensureDirectoryExists($dir);

        try {
            $list = [];

            foreach (array_values($clipPaths) as $i => $clip) {
                $out = "{$dir}/n{$i}.mp4";
                $seconds = max(0.1, $this->duration($clip));
                $video = "[0:v]scale={$w}:{$h}:force_original_aspect_ratio=increase,crop={$w}:{$h},fps={$fps},setsar=1,format=yuv420p[v]";

                // Keep the clip's own sound (Veo renders audio); silence for clips without any.
                // Audio is padded/trimmed to the clip's length and faded at the cut to avoid clicks.
                if ($this->hasAudio($clip)) {
                    $fadeOut = max(0, $seconds - 0.12);
                    $inputs = [$ffmpeg, '-y', '-i', $clip];
                    $audio = "[0:a]aresample=48000,aformat=sample_fmts=fltp:channel_layouts=stereo,apad,atrim=0:{$seconds},asetpts=N/SR/TB,afade=t=in:d=0.06,afade=t=out:st={$fadeOut}:d=0.12[a]";
                } else {
                    $inputs = [$ffmpeg, '-y', '-i', $clip, '-f', 'lavfi', '-t', (string) $seconds, '-i', 'anullsrc=channel_layout=stereo:sample_rate=48000'];
                    $audio = '[1:a]aformat=sample_fmts=fltp:channel_layouts=stereo[a]';
                }

                $this->run([
                    ...$inputs,
                    '-filter_complex', "{$video};{$audio}",
                    '-map', '[v]', '-map', '[a]',
                    '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '19',
                    '-c:a', 'aac', '-b:a', '160k', '-ar', '48000', '-ac', '2',
                    $out,
                ]);
                $list[] = "file '".str_replace("'", "'\\''", $out)."'";
            }

            File::put("{$dir}/list.txt", implode("\n", $list));

            $joined = "{$dir}/joined.mp4";
            $this->run([$ffmpeg, '-y', '-f', 'concat', '-safe', '0', '-i', "{$dir}/list.txt", '-c', 'copy', $joined]);

            // One loudness for the whole reel so clips from different models match.
            $final = "{$dir}/reel.mp4";
            $this->run([
                $ffmpeg, '-y', '-i', $joined,
                '-map', '0:v', '-map', '0:a',
                '-af', 'loudnorm=I=-16:TP=-1.5:LRA=11',
                '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '19', '-pix_fmt', 'yuv420p', '-r', (string) $fps,
                '-c:a', 'aac', '-b:a', '160k', '-ar', '48000',
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
     * A silent clip made from one still (free, no video model): a locked shot or a
     * slow Ken Burns move. Text and logos stay perfectly sharp, so it suits
     * title / info / end cards — and it keeps a reel deliverable when the video
     * models are out of quota.
     *
     * @param  'still'|'push_in'|'pull_out'|'pan_left'|'pan_right'  $motion
     * @return string Absolute path of the MP4 (caller moves it).
     */
    public function stillClip(string $imagePath, int $seconds, string $motion, int $w, int $h): string
    {
        $fps = config('creations.reel.fps');
        $frames = max(1, $seconds * $fps);
        $last = max(1, $frames - 1);
        [$z, $x, $y] = match ($motion) {
            'push_in' => ["1+0.08*on/{$last}", 'iw/2-(iw/zoom/2)', 'ih/2-(ih/zoom/2)'],
            'pull_out' => ["1.08-0.08*on/{$last}", 'iw/2-(iw/zoom/2)', 'ih/2-(ih/zoom/2)'],
            'pan_left' => ['1.1', "(iw-iw/zoom)*(1-on/{$last})", 'ih/2-(ih/zoom/2)'],
            'pan_right' => ['1.1', "(iw-iw/zoom)*on/{$last}", 'ih/2-(ih/zoom/2)'],
            default => ['1', '0', '0'],
        };

        $out = storage_path('app/private/reels/'.Str::uuid().'.mp4');
        File::ensureDirectoryExists(dirname($out));
        $w2 = $w * 2;
        $h2 = $h * 2;

        // Work at 2× so the slow zoom does not shimmer, then render at the reel size.
        $this->run([
            config('ai.ffmpeg'), '-y', '-loop', '1', '-i', $imagePath,
            '-vf', "scale={$w2}:{$h2}:force_original_aspect_ratio=increase,crop={$w2}:{$h2},zoompan=z='{$z}':x='{$x}':y='{$y}':d={$frames}:s={$w}x{$h}:fps={$fps},setsar=1,format=yuv420p",
            '-frames:v', (string) $frames, '-an', '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '18',
            $out,
        ]);

        return $out;
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

    private function hasAudio(string $path): bool
    {
        $result = Process::timeout(60)->run([
            config('ai.ffprobe'), '-v', 'error', '-select_streams', 'a', '-show_entries', 'stream=index', '-of', 'csv=p=0', $path,
        ]);

        return trim($result->output()) !== '';
    }

    private function run(array $command): void
    {
        $result = Process::timeout(900)->run($command);

        if ($result->failed()) {
            throw new AiException('ffmpeg failed: '.Str::limit(trim($result->errorOutput()), 400));
        }
    }
}
