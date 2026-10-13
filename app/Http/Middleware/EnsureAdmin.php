<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// `admin` middleware: зөвхөн админ хэрэглэгчийг нэвтрүүлнэ.
class EnsureAdmin
{
    // Админ биш бол 403 буцаана, админ бол хүсэлтийг үргэлжлүүлнэ.
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->is_admin, 403);

        return $next($request);
    }
}
