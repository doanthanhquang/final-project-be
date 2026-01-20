<?php

namespace App\Http\Controllers;

use App\Models\AuthToken;
use App\Models\User;
use App\Services\TokenService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function __construct(
        private TokenService $tokenService
    ) {}

    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string|min:6',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = User::where('email', $request->email)->first();
        if (! $user || ! Hash::check($request->password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid email or password',
            ], 401);
        }

        $tokenData = $this->tokenService->createTokensForUser($user);

        // Set refresh token in httpOnly cookie (server-side only)
        $response = response()->json(
            $this->tokenService->buildAuthResponse($user, $tokenData)
        );

        // Store refresh token in httpOnly cookie for security
        $response->cookie(
            'refresh_token',
            $tokenData['token']->refresh_token,
            config('session.lifetime', 10080), // 7 days in minutes
            '/',
            null,
            true, // secure (HTTPS only)
            true  // httpOnly (not accessible via JavaScript)
        );

        return $response;
    }

    public function refresh(Request $request)
    {
        // Get refresh token from httpOnly cookie (server-side only)
        $refreshToken = $request->cookie('refresh_token');

        if (! $refreshToken) {
            return response()->json([
                'success' => false,
                'message' => 'Refresh token not found',
            ], 401);
        }

        $token = AuthToken::where('refresh_token', $refreshToken)
            ->where('revoked', false)
            ->first();

        if (! $token || $token->refresh_expires_at->isPast()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired refresh token',
            ], 401);
        }

        // Rotate access token
        $token->access_token = Str::random(64);
        $token->access_expires_at = Carbon::now()->addMinutes(15);
        $token->save();

        return response()->json([
            'success' => true,
            'accessToken' => $token->access_token,
            'accessTokenExpiresAt' => $token->access_expires_at->toIso8601String(),
        ]);
    }

    public function logout(Request $request)
    {
        $bearer = $request->bearerToken();
        if ($bearer) {
            AuthToken::where('access_token', $bearer)->update(['revoked' => true]);
        }

        // Revoke refresh token from cookie
        $refreshToken = $request->cookie('refresh_token');
        if ($refreshToken) {
            AuthToken::where('refresh_token', $refreshToken)->update(['revoked' => true]);
        }

        $response = response()->json([
            'success' => true,
            'message' => 'Logged out',
        ]);

        // Clear refresh token cookie
        return $response->cookie('refresh_token', '', -1, '/', null, true, true);
    }

    public function me(Request $request)
    {
        $user = $request->attributes->get('auth_user');
        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'avatar' => $user->avatar,
            'provider' => $user->provider ?? 'email',
        ]);
    }
}
