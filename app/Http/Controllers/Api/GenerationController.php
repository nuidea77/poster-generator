<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Generation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class GenerationController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            Generation::latest()->limit(60)->get()
        );
    }

    public function show(Generation $generation): JsonResponse
    {
        return response()->json($generation);
    }

    /**
     * Save edits made in the browser (texts, colors, generated image URLs).
     */
    public function update(Request $request, Generation $generation): JsonResponse
    {
        $data = $request->validate([
            'content' => ['required', 'array'],
        ]);

        $generation->update(['content' => $data['content']]);

        return response()->json($generation);
    }

    public function destroy(Generation $generation): Response
    {
        $generation->delete();

        return response()->noContent();
    }
}
