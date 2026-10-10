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

class CreationController extends Controller
{
    public function __construct(private CreationService $creations) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = $request->user()->creations()->latest();

        if (in_array($request->query('type'), [Creation::POSTER, Creation::REEL], true)) {
            $query->where('type', $request->query('type'));
        }

        return CreationResource::collection($query->paginate(24));
    }

    public function store(StoreCreationRequest $request): JsonResponse
    {
        $creation = $this->creations->start(
            $request->user(),
            $request->validated(),
            $request->file('logo'),
            $request->file('images', []),
        );

        return (new CreationResource($creation->refresh()))->response()->setStatusCode(202);
    }

    public function show(Request $request, Creation $creation): CreationResource
    {
        $this->authorizeOwner($request, $creation);

        return new CreationResource($creation);
    }

    public function retry(Request $request, Creation $creation): JsonResponse
    {
        $this->authorizeOwner($request, $creation);

        return (new CreationResource($this->creations->retry($creation)->refresh()))->response()->setStatusCode(202);
    }

    public function destroy(Request $request, Creation $creation): Response
    {
        $this->authorizeOwner($request, $creation);
        abort_if($creation->isActive(), 409, 'Бүтээл ажиллаж байна.');

        MediaStore::deleteDirectory("creations/{$creation->public_id}");
        $creation->delete();

        return response()->noContent();
    }

    private function authorizeOwner(Request $request, Creation $creation): void
    {
        abort_unless($creation->user_id === $request->user()->id || $request->user()->is_admin, 404);
    }
}
