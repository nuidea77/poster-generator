<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminCreationResource;
use App\Models\Creation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

// Админд зориулсан бүтээлүүд (дотоод мэдээлэлтэй нь).
class CreationController extends Controller
{
    // Бүх хэрэглэгчийн бүтээлийг шинээс нь хуудаслан жагсаана.
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Creation::with('user')->latest();

        // Төлөвөөр шүүх (сонголттой).
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return AdminCreationResource::collection($query->paginate(30));
    }

    // Нэг бүтээлийг бүх дотоод мэдээлэлтэй нь харуулна.
    public function show(Creation $creation): AdminCreationResource
    {
        return new AdminCreationResource($creation->load('user'));
    }
}
