<?php

namespace App\Services;

use App\Jobs\RunCreation;
use App\Models\Creation;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class CreationService
{
    /**
     * Validate fair-use limits, store uploads and queue the job.
     *
     * @param  array<int, UploadedFile>  $images
     */
    public function start(User $user, array $data, ?UploadedFile $logo, array $images): Creation
    {
        $this->guardFairUse($user, $data['type']);

        $creation = Creation::create([
            'user_id' => $user->id,
            'type' => $data['type'],
            'formats' => $data['type'] === Creation::POSTER ? array_values(array_unique($data['formats'])) : null,
            'prompt' => $data['prompt'],
            'product' => array_filter($data['product'] ?? [], 'filled') ?: null,
            'status' => Creation::QUEUED,
            'stage' => 'queued',
        ]);

        $dir = "creations/{$creation->public_id}/inputs";
        $inputs = [];

        if ($logo) {
            $path = $logo->store($dir, 'public');
            $inputs[] = ['id' => 'logo', 'kind' => 'image', 'role' => 'logo', 'source' => 'upload', 'path' => $path];

            if (! empty($data['remember_logo'])) {
                $saved = $logo->store('logos', 'public');
                if ($user->logo_path) {
                    Storage::disk('public')->delete($user->logo_path);
                }
                $user->update(['logo_path' => $saved]);
            }
        } elseif (! empty($data['use_saved_logo']) && $user->logo_path && Storage::disk('public')->exists($user->logo_path)) {
            $path = "{$dir}/logo.".pathinfo($user->logo_path, PATHINFO_EXTENSION);
            Storage::disk('public')->copy($user->logo_path, $path);
            $inputs[] = ['id' => 'logo', 'kind' => 'image', 'role' => 'logo', 'source' => 'upload', 'path' => $path];
        }

        foreach (array_values($images) as $i => $image) {
            $inputs[] = ['id' => 'product_'.($i + 1), 'kind' => 'image', 'role' => 'product', 'source' => 'upload', 'path' => $image->store($dir, 'public')];
        }

        $creation->update(['inputs' => $inputs]);

        RunCreation::dispatch($creation);

        return $creation;
    }

    /**
     * Re-run the same brief and attachments as a new creation.
     */
    public function retry(Creation $source): Creation
    {
        $this->guardFairUse($source->user, $source->type);

        $creation = Creation::create([
            'user_id' => $source->user_id,
            'type' => $source->type,
            'formats' => $source->formats,
            'prompt' => $source->prompt,
            'product' => $source->product,
            'status' => Creation::QUEUED,
            'stage' => 'queued',
        ]);

        $inputs = [];
        foreach ($source->inputs as $input) {
            $path = "creations/{$creation->public_id}/inputs/".basename($input['path']);
            if (Storage::disk('public')->exists($input['path'])) {
                Storage::disk('public')->copy($input['path'], $path);
                $inputs[] = ['path' => $path] + $input;
            }
        }

        $creation->update(['inputs' => $inputs]);

        RunCreation::dispatch($creation);

        return $creation;
    }

    private function guardFairUse(User $user, string $type): void
    {
        if ($user->is_admin) {
            return;
        }

        $active = $user->creations()->whereIn('status', Creation::ACTIVE)->count();

        if ($active >= config('creations.max_active_per_user')) {
            throw ValidationException::withMessages([
                'type' => 'Өмнөх бүтээл тань дуусаагүй байна. Түүнийг дуусмагц дахин оролдоно уу.',
            ])->status(429);
        }

        $limit = config("creations.daily_limit.{$type}");

        if ($limit !== null && $user->creations()->where('type', $type)->where('created_at', '>=', now()->startOfDay())->count() >= $limit) {
            throw ValidationException::withMessages([
                'type' => 'Өнөөдрийн хэрэглээний хязгаарт хүрлээ. Маргааш дахин оролдоно уу.',
            ])->status(429);
        }
    }
}
