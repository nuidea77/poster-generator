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

class RunCreation implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public function __construct(public Creation $creation)
    {
        $this->timeout = config('creations.job_timeout');
    }

    public function handle(CreativeAgent $agent, ReelAssembler $assembler): void
    {
        set_time_limit(0);

        $creation = $this->creation;

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

    private function completePoster(Creation $creation): void
    {
        $delivered = array_column($creation->outputs, 'format');
        $missing = array_values(array_diff($creation->formats, $delivered));

        if (! $delivered) {
            throw new \RuntimeException('Agent finished without delivering any poster.');
        }

        // Keep outputs in the order the user picked the formats.
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

    private function completeReel(Creation $creation, ReelAssembler $assembler): void
    {
        if (! $creation->reel_clips) {
            throw new \RuntimeException('Agent finished without delivering the reel.');
        }

        $creation->update(['status' => Creation::ASSEMBLING, 'stage' => 'assembling', 'progress' => max($creation->progress, 88)]);

        $paths = array_map(fn ($id) => MediaStore::absolute($creation->asset($id)['path']), $creation->reel_clips);
        $tmp = $assembler->assemble($paths);

        $duration = round($assembler->duration($tmp), 1);
        $file = MediaStore::put("creations/{$creation->public_id}", File::get($tmp), 'video/mp4');
        $thumb = MediaStore::put("creations/{$creation->public_id}", $assembler->thumbnail($tmp, min(2.0, $duration / 2)), 'image/jpeg');
        File::delete($tmp);

        $reel = config('creations.reel');

        $creation->update([
            'outputs' => [[
                'kind' => 'video', 'format' => 'reel', 'label' => 'Reels 9:16',
                'width' => $reel['width'], 'height' => $reel['height'], 'duration' => $duration,
                'path' => $file['path'], 'url' => $file['url'], 'thumb_url' => $thumb['url'],
            ]],
            'status' => Creation::DONE,
            'stage' => 'done',
            'progress' => 100,
            'finished_at' => now(),
        ]);
    }
}
