<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tymon\JWTAuth\Facades\JWTAuth;

class CheckRole
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        $user = $request->get('auth_user');

        if (!$user || !in_array($user['role_name'], $roles)) {
            return response()->json([
                'error' => 'Akses ditolak.',
                'message' => 'Anda tidak memiliki izin untuk mengakses resource ini.'
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
