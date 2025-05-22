<?php

namespace App\Http\Middleware;

use Closure;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Exception;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyJwtToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $authHeader = $request->header('Authorization');
        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            return response()->json(['error' => 'Token tidak ditemukan.'], 401);
        }

        $token = substr($authHeader, 7);
        try {
            $decoded = JWT::decode($token, new Key(env('JWT_SECRET'), 'HS256'));

            $request->merge(['auth_user' => (array) $decoded]);

            return $next($request);
        } catch (Exception $e) {
            return response()->json([
                'error' => 'Token tidak valid.',
                'message' => $e->getMessage()
            ], 401);
        }
    }
}
