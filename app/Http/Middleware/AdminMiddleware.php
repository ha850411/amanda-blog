<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AdminMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!Auth::guard('admin')->check()) {
            // source by api
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => '未登入',
                ], 401);
            }
            return redirect()->route('admin.login');
        }

        return $next($request);
    }
}
