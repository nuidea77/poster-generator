<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCreationRequest;
use App\Http\Resources\CreationResource;
use App\Models\Creation;
use App\Services\CreationService;
use App\Services\Media\MediaStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

// Хэрэглэгчийн өөрийн бүтээлүүд: жагсаах, эхлүүлэх, харах, дахин оролдох, устгах.
class CreationController extends Controller
{
    // Бүтээлийн бизнес логикийг CreationService-д даатгана.
    public function __construct(private CreationService $creations) {}

    // Хэрэглэгчийн бүтээлүүдийг төрөл, төлөвөөр шүүж хуудаслана.
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = $request->user()->creations()->latest()->orderByDesc('id'); // нэг секундэд үүссэн ажлууд хуудас хооронд дарааллаа хадгална

        // Төрлөөр шүүх: зөвхөн poster эсвэл reel.
        if (in_array($request->query('type'), [Creation::POSTER, Creation::REEL], true)) {
            $query->where('type', $request->query('type'));
        }

        // Төлөвөөр шүүх: дууссан, алдаатай, эсвэл явж буй.
        match ($request->query('status')) {
            'done' => $query->where('status', Creation::DONE),
            'failed' => $query->where('status', Creation::FAILED),
            'active' => $query->whereIn('status', Creation::ACTIVE),
            default => null,
        };

        return CreationResource::collection($query->paginate(24));
    }

    // Шинэ бүтээл эхлүүлнэ; ажил дараалалд орох тул 202 буцаана.
    public function store(StoreCreationRequest $request): JsonResponse
    {
        $creation = $this->creations->start(
            $request->user(),
            $request->validated(),
            $request->file('logo'),
            $request->file('images', []),
            $request->file('references', []),
        );

        return (new CreationResource($creation->refresh()))->response()->setStatusCode(202);
    }

    // Нэг бүтээлийг эзэнд нь (эсвэл админд) харуулна.
    public function show(Request $request, Creation $creation): CreationResource
    {
        $this->authorizeOwner($request, $creation);

        return new CreationResource($creation);
    }

    // Алдаатай бүтээлийг дахин ажиллуулна.
    public function retry(Request $request, Creation $creation): JsonResponse
    {
        $this->authorizeOwner($request, $creation);

        return (new CreationResource($this->creations->retry($creation)->refresh()))->response()->setStatusCode(202);
    }

    // Бэлэн постерыг засварлана: хэрэглэгчийн хүсэлтээр шинэ хувилбар үүсгэнэ (credit хасагдана).
    public function revise(Request $request, Creation $creation): JsonResponse
    {
        abort_unless($creation->user_id === $request->user()->id, 404);
        $data = $request->validate(['request' => ['required', 'string', 'min:3', 'max:1000']]);

        return (new CreationResource($this->creations->revise($creation, $data['request'])->refresh()))->response()->setStatusCode(202);
    }

    // Дууссан бүтээлийг эзэн нь үнэлнэ (1 = таалагдсан, -1 = үгүй) ба сонголттой санал үлдээнэ.
    public function rate(Request $request, Creation $creation): CreationResource
    {
        // Зөвхөн эзэн (админ ч бусдын өмнөөс үнэлэхгүй).
        abort_unless($creation->user_id === $request->user()->id, 404);
        abort_unless($creation->status === Creation::DONE, 409, 'Зөвхөн бэлэн болсон бүтээлийг үнэлнэ.');

        $data = $request->validate([
            'rating' => ['required', 'integer', 'in:1,-1'],
            'feedback' => ['nullable', 'string', 'max:500'],
        ]);

        $creation->update([
            'rating' => (int) $data['rating'],
            'rated_at' => now(),
            'feedback' => filled($data['feedback'] ?? null) ? trim($data['feedback']) : null,
        ]);

        return new CreationResource($creation);
    }

    // Бүтээл болон түүний файлуудыг устгана; ажиллаж байгаа бол 409.
    public function destroy(Request $request, Creation $creation): Response
    {
        $this->authorizeOwner($request, $creation);
        abort_if($creation->isActive(), 409, 'Бүтээл ажиллаж байна.');

        // Файлуудыг эхлээд, дараа нь мөрийг устгана.
        MediaStore::deleteDirectory("creations/{$creation->public_id}");
        $creation->delete();

        return response()->noContent();
    }

    // Эзэн эсвэл админ биш бол 404 (бүтээл байгааг ч илчлэхгүй).
    private function authorizeOwner(Request $request, Creation $creation): void
    {
        abort_unless($creation->user_id === $request->user()->id || $request->user()->is_admin, 404);
    }
}
