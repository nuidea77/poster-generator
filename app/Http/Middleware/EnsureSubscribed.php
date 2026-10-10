<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSubscribed
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isSubscribed()) {
            return response()->json([
                'message' => 'Идэвхтэй багц шаардлагатай.',
                'code' => 'subscription_required',
            ], 402);
        }

        return $next($request);
    }
}
