<?php

namespace App\Jobs;

use App\Models\Creation;
use App\Services\Agent\CreativeAgent;
use App\Services\Billing\Credits;
use App\Services\Media\MediaStore;
use App\Services\Media\ReelAssembler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Нэг бүтээлийг ажиллуулах queue job: CreativeAgent-ийг ажиллуулаад
 * poster-ийг дуусгах эсвэл reel-ийг ffmpeg-ээр угсарна.
 */
class RunCreation implements ShouldQueue
{
    use Queueable;

    // Дахин оролдохгүй: агент мөнгө зарцуулдаг тул давтаж ажиллуулахгүй.
    public int $tries = 1;

    // Job-ийн хугацааны хязгаар (секунд), config-оос.
    public int $timeout;

    // Timeout-ыг config-оос тохируулна.
    public function __construct(public Creation $creation)
    {
        $this->timeout = config('creations.job_timeout');
    }

    // Агентыг ажиллуулж, үр дүнг төрлөөр нь дуусгана; алдаа гарвал failed() руу.
    public function handle(CreativeAgent $agent, ReelAssembler $assembler): void
    {
        set_time_limit(0);

        $creation = $this->creation;

        // Зөвхөн дараалалд байгаа бүтээлийг ажиллуулна (давхар ажиллахаас сэргийлнэ).
        if ($creation->status !== Creation::QUEUED) {
            return;
        }

        $creation->update(['status' => Creation::RUNNING, 'started_at' => now(), 'stage' => 'planning', 'progress' => 3]);

        try {
            $agent->run($creation);
            $creation->refresh();

            $creation->type === Creation::REEL
                ? $this->completeReel($creation, $assembler)
                : $this->completePoster($creation);
        } catch (Throwable $e) {
            $this->failed($e);
        }
    }

    // Бүтээлийг алдаатай гэж тэмдэглээд, хэрэглэгчийн credit-ийг буцаана.
    public function failed(Throwable $e): void
    {
        Log::error('Creation failed', ['creation' => $this->creation->public_id, 'error' => $e->getMessage()]);

        $this->creation->refresh()->update([
            'status' => Creation::FAILED,
            'error_detail' => $e->getMessage(),
            'finished_at' => now(),
        ]);

        app(Credits::class)->refund($this->creation);
    }

    // Poster-ийг дуусгана: дутуу формат байвал error_detail-д тэмдэглэнэ.
    private function completePoster(Creation $creation): void
    {
        $delivered = array_column($creation->outputs, 'format');
        $missing = array_values(array_diff($creation->formats, $delivered));

        // Нэг ч poster гараагүй бол алдаа.
        if (! $delivered) {
            throw new \RuntimeException('Agent finished without delivering any poster.');
        }

        // Үр дүнг хэрэглэгчийн сонгосон форматын дарааллаар хадгална.
        $order = array_flip($creation->formats);
        $outputs = $creation->outputs;
        usort($outputs, fn ($a, $b) => $order[$a['format']] <=> $order[$b['format']]);

        $creation->update([
            'outputs' => $outputs,
            'status' => Creation::DONE,
            'stage' => 'done',
            'progress' => 100,
            'finished_at' => now(),
            'error_detail' => $missing ? 'Missing formats: '.implode(', ', $missing) : null,
        ]);
    }

    // Reel-ийн клипүүдийг нэг видео болгон угсарч, thumbnail үүсгэнэ.
    private function completeReel(Creation $creation, ReelAssembler $assembler): void
    {
        // Агент клип сонгоогүй бол алдаа.
        if (! $creation->reel_clips) {
            throw new \RuntimeException('Agent finished without delivering the reel.');
        }

        $creation->update(['status' => Creation::ASSEMBLING, 'stage' => 'assembling', 'progress' => max($creation->progress, 88)]);

        // Клипүүдийг сонгосон дарааллаар нь reel-ийн хэмжээнд угсарна.
        $paths = array_map(fn ($id) => MediaStore::absolute($creation->asset($id)['path']), $creation->reel_clips);
        $format = $creation->reelFormat();
        $tmp = $assembler->assemble($paths, $format['width'], $format['height']);

        // Бэлэн видео болон thumbnail-ийг хадгалаад түр файлыг устгана.
        $duration = round($assembler->duration($tmp), 1);
        $file = MediaStore::put("creations/{$creation->public_id}", File::get($tmp), 'video/mp4');
        $thumb = MediaStore::put("creations/{$creation->public_id}", $assembler->thumbnail($tmp, min(2.0, $duration / 2)), 'image/jpeg');
        File::delete($tmp);

        $creation->update([
            'outputs' => [[
                'kind' => 'video', 'format' => 'reel', 'label' => "Reels {$format['label']}",
                'width' => $format['width'], 'height' => $format['height'], 'duration' => $duration,
                'path' => $file['path'], 'url' => $file['url'], 'thumb_url' => $thumb['url'],
            ]],
            'status' => Creation::DONE,
            'stage' => 'done',
            'progress' => 100,
            'finished_at' => now(),
        ]);
    }
}
