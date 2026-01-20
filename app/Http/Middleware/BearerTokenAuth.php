<?php

namespace App\Http\Middleware;

use App\Models\AuthToken;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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

        if (! $token) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        // Check if access token is expired
        $isExpired = $token->access_expires_at->isPast();
        $shouldRefresh = $isExpired && $token->refresh_expires_at && ! $token->refresh_expires_at->isPast();

        // If expired but refresh token is still valid, automatically refresh
        if ($shouldRefresh) {
            // Rotate access token
            $token->access_token = Str::random(64);
            $token->access_expires_at = Carbon::now()->addMinutes(15);
            $token->save();

            // Continue with the request using the new token
            $tokenString = $token->access_token;
        } elseif ($isExpired) {
            // Access token expired and refresh token also expired
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        // Attach user to request for controllers
        $request->attributes->set('auth_user', $token->user);

        // Process the request
        $response = $next($request);

        // If token was refreshed, add new access token to response header
        if ($shouldRefresh) {
            $response->headers->set('X-New-Access-Token', $tokenString);
            $response->headers->set('X-Access-Token-Expires-At', $token->access_expires_at->toIso8601String());
        }

        return $response;
    }
}
