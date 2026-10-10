<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminCreationResource;
use App\Models\Creation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CreationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Creation::with('user')->latest();

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return AdminCreationResource::collection($query->paginate(30));
    }

    public function show(Creation $creation): AdminCreationResource
    {
        return new AdminCreationResource($creation->load('user'));
    }
}
