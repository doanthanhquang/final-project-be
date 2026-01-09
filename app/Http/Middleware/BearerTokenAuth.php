<?php

namespace App\Http\Middleware;

use App\Models\AuthToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BearerTokenAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $tokenString = $request->bearerToken();
        if (! $tokenString) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $token = AuthToken::with('user')
            ->where('access_token', $tokenString)
            ->where('revoked', false)
            ->first();

        if (! $token || $token->access_expires_at->isPast()) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        // Attach user to request for controllers
        $request->attributes->set('auth_user', $token->user);

        return $next($request);
    }
}
