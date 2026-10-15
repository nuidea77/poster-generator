<?php

namespace App\Services;

use App\Jobs\RunCreation;
use App\Models\Creation;
use App\Models\User;
use App\Services\Billing\Credits;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Бүтээл (постер / reel) эхлүүлэх: хязгаар шалгах, credit хасах, файл хадгалах, job дараалалд оруулах.
 */
class CreationService
{
    // Ажлын үнийг credit-ээр хасахад ашиглана.
    public function __construct(private Credits $credits) {}

    /**
     * Шударга хэрэглээний хязгаарыг шалгаж, оруулсан файлуудыг хадгалаад job-ийг дараалалд оруулна.
     *
     * @param  array<int, UploadedFile>  $images
     * @param  array<int, UploadedFile>  $references
     */
    public function start(User $user, array $data, ?UploadedFile $logo, array $images, array $references = []): Creation
    {
        $this->guardFairUse($user, $data['type']);

        // Постер олон хэмжээтэй байж болно; reel нэг л форматтай, урт нь сонгосон (эсвэл анхдагч) утга.
        $creation = $this->createCharged($user, [
            'type' => $data['type'],
            'formats' => $data['type'] === Creation::POSTER
                ? array_values(array_unique($data['formats']))
                : [$data['formats'][0] ?? array_key_first(config('creations.reel.formats'))],
            'duration' => $data['type'] === Creation::REEL ? (int) ($data['duration'] ?? array_key_first(config('creations.reel.durations'))) : null,
            'prompt' => $data['prompt'],
            'product' => array_filter($data['product'] ?? [], 'filled') ?: null,
        ]);

        $dir = "creations/{$creation->public_id}/inputs";
        $inputs = [];

        // Лого: шинээр оруулсныг хадгална (хүсвэл брэндийн логог солино), эсвэл брэндийн хадгалсан логог хуулна.
        // Брэндийн лого анхдагчаар хавсрагдана; хэрэглэгч энэ ажлаас хассан бол use_saved_logo=0 ирнэ.
        $useSaved = ! array_key_exists('use_saved_logo', $data) || filter_var($data['use_saved_logo'], FILTER_VALIDATE_BOOLEAN);

        if ($logo) {
            $path = $logo->store($dir, 'public');
            $inputs[] = ['id' => 'logo', 'kind' => 'image', 'role' => 'logo', 'source' => 'upload', 'path' => $path];

            if (! empty($data['remember_logo'])) {
                $saved = $logo->store('logos', 'public');
                if ($user->brand_logo_path) {
                    Storage::disk('public')->delete($user->brand_logo_path);
                }
                $user->update(['brand_logo_path' => $saved]);
            }
        } elseif ($useSaved && $user->brand_logo_path && Storage::disk('public')->exists($user->brand_logo_path)) {
            $path = "{$dir}/logo.".pathinfo($user->brand_logo_path, PATHINFO_EXTENSION);
            Storage::disk('public')->copy($user->brand_logo_path, $path);
            $inputs[] = ['id' => 'logo', 'kind' => 'image', 'role' => 'logo', 'source' => 'upload', 'path' => $path];
        }

        // Бүтээгдэхүүний зураг болон загвар (reference) зургуудыг дугаарлан хадгална.
        foreach (array_values($images) as $i => $image) {
            $inputs[] = ['id' => 'product_'.($i + 1), 'kind' => 'image', 'role' => 'product', 'source' => 'upload', 'path' => $image->store($dir, 'public')];
        }

        foreach (array_values($references) as $i => $image) {
            $inputs[] = ['id' => 'reference_'.($i + 1), 'kind' => 'image', 'role' => 'reference', 'source' => 'upload', 'path' => $image->store($dir, 'public')];
        }

        $creation->update(['inputs' => $inputs]);

        RunCreation::dispatch($creation);

        return $creation;
    }

    /**
     * Ижил даалгавар болон хавсралтуудаар шинэ бүтээл болгон дахин ажиллуулна.
     */
    public function retry(Creation $source): Creation
    {
        $this->guardFairUse($source->user, $source->type);

        $creation = $this->createCharged($source->user, [
            'type' => $source->type,
            'formats' => $source->formats,
            'duration' => $source->duration,
            'prompt' => $source->prompt,
            'product' => $source->product,
        ]);

        $creation->update(['inputs' => $this->copyInputs($source, $creation)]);

        RunCreation::dispatch($creation);

        return $creation;
    }

    /**
     * Бэлэн постерыг хэрэглэгчийн хүсэлтээр засварлана: шинэ бүтээл (parent_id) болж, эх постер
     * болон түүний текстгүй цэвэр зургийг авч явна — ингэснээр agent зөвхөн хүссэн зүйлийг өөрчилнө.
     */
    public function revise(Creation $source, string $request): Creation
    {
        abort_unless($source->type === Creation::POSTER && $source->status === Creation::DONE && $source->outputs, 409, 'Зөвхөн бэлэн болсон постерыг засварлана.');
        $this->guardFairUse($source->user, $source->type);

        $creation = $this->createCharged($source->user, [
            'parent_id' => $source->id,
            'revision' => trim($request),
            'type' => $source->type,
            'formats' => $source->formats,
            'duration' => $source->duration,
            'prompt' => $source->prompt,
            'product' => $source->product,
        ]);

        // Анхны оролтууд (лого, бүтээгдэхүүн, жишээ), дээр нь формат бүрийн бэлэн постер ба цэвэр зураг.
        $disk = Storage::disk('public');
        $dir = "creations/{$creation->public_id}";
        $inputs = array_values(array_filter($this->copyInputs($source, $creation), fn ($i) => $i['role'] !== 'previous'));
        $assets = [];
        foreach (array_values($source->outputs) as $n => $output) {
            if (($output['kind'] ?? 'image') !== 'image' || ! $disk->exists($output['path'] ?? '')) {
                continue;
            }
            $k = $n + 1;
            $path = "{$dir}/inputs/previous_{$k}.".pathinfo($output['path'], PATHINFO_EXTENSION);
            $disk->copy($output['path'], $path);
            $inputs[] = ['id' => "previous_{$k}", 'kind' => 'image', 'role' => 'previous', 'source' => 'upload', 'path' => $path, 'format' => $output['format'] ?? null];

            [$clean] = $source->cleanVisual($output['source_asset'] ?? null);
            if ($clean && $disk->exists($clean['path'])) {
                $id = 'img_'.(count($assets) + 1);
                $copy = "{$dir}/assets/{$id}.".pathinfo($clean['path'], PATHINFO_EXTENSION);
                $disk->copy($clean['path'], $copy);
                // Өртөг 0: зургийг өмнөх ажил аль хэдийн төлсөн.
                $assets[] = ['id' => $id, 'kind' => 'image', 'source' => 'generated', 'provider' => $clean['provider'] ?? 'previous', 'path' => $copy,
                    'aspect' => $clean['aspect'] ?? null, 'cost' => 0.0, 'note' => 'clean visual of the previous version', 'revision_of' => $output['format'] ?? null];
            }
        }

        $creation->update(['inputs' => $inputs, 'assets' => $assets]);

        RunCreation::dispatch($creation);

        return $creation;
    }

    /**
     * Эх бүтээлийн оруулсан файлуудыг шинэ хавтас руу хуулна; устсан файлыг алгасна.
     *
     * @return list<array<string, mixed>>
     */
    private function copyInputs(Creation $source, Creation $creation): array
    {
        $inputs = [];
        foreach ($source->inputs as $input) {
            $path = "creations/{$creation->public_id}/inputs/".basename($input['path']);
            if (Storage::disk('public')->exists($input['path'])) {
                Storage::disk('public')->copy($input['path'], $path);
                $inputs[] = ['path' => $path] + $input;
            }
        }

        return $inputs;
    }

    /**
     * Job үүсгэж үнийг нь credit-ээр хасна; хэтэвч хүрэлцэхгүй бол юу ч хадгалагдахгүй (transaction буцна).
     */
    private function createCharged(User $user, array $attributes): Creation
    {
        return DB::transaction(function () use ($user, $attributes) {
            $creation = Creation::create($attributes + ['user_id' => $user->id, 'status' => Creation::QUEUED, 'stage' => 'queued']);
            $this->credits->charge($user, $creation, $this->credits->price($creation));

            return $creation;
        });
    }

    /**
     * Шударга хэрэглээний хязгаар: зэрэг ажиллаж буй болон өдрийн бүтээлийн тоо (админд хамаарахгүй), хэтэрвэл 429.
     */
    private function guardFairUse(User $user, string $type): void
    {
        if ($user->is_admin) {
            return;
        }

        // Нэг дор ажиллаж буй бүтээлийн тоо.
        $active = $user->creations()->whereIn('status', Creation::ACTIVE)->count();

        if ($active >= config('creations.max_active_per_user')) {
            throw ValidationException::withMessages([
                'type' => 'Өмнөх бүтээл тань дуусаагүй байна. Түүнийг дуусмагц дахин оролдоно уу.',
            ])->status(429);
        }

        // Төрөл тус бүрийн өдрийн хязгаар (null бол хязгааргүй).
        $limit = config("creations.daily_limit.{$type}");

        if ($limit !== null && $user->creations()->where('type', $type)->where('created_at', '>=', now()->startOfDay())->count() >= $limit) {
            throw ValidationException::withMessages([
                'type' => 'Өнөөдрийн хэрэглээний хязгаарт хүрлээ. Маргааш дахин оролдоно уу.',
            ])->status(429);
        }
    }
}
