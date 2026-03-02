<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        // 確認是否登入
        // if (!Auth::check()) {
        return redirect()->route('login');
        // }

        return $next($request);
    }
}
