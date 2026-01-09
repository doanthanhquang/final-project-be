<?php

namespace App\Http\Controllers;

use App\Models\AuthToken;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AuthController extends Controller
{
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

        // Revoke old tokens for this device (optional policy: keep multiple sessions?)
        AuthToken::where('user_id', $user->id)
            ->where('revoked', false)
            ->update(['revoked' => true]);

        $now = Carbon::now();
        $accessExpiresAt = $now->copy()->addMinutes(15);
        $refreshExpiresAt = $now->copy()->addDays(7);

        $token = AuthToken::create([
            'user_id' => $user->id,
            'access_token' => Str::random(64),
            'access_expires_at' => $accessExpiresAt,
            'refresh_token' => Str::random(64),
            'refresh_expires_at' => $refreshExpiresAt,
        ]);

        return response()->json([
            'success' => true,
            'accessToken' => $token->access_token,
            'accessTokenExpiresAt' => $accessExpiresAt->toIso8601String(),
            'refreshToken' => $token->refresh_token,
            'refreshTokenExpiresAt' => $refreshExpiresAt->toIso8601String(),
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar' => $user->avatar,
                'provider' => $user->provider ?? 'email',
            ],
        ]);
    }

    public function refresh(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'refreshToken' => 'required|string',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $token = AuthToken::where('refresh_token', $request->input('refreshToken'))
            ->where('revoked', false)
            ->first();

        if (! $token || $token->refresh_expires_at->isPast()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired refresh token',
            ], 401);
        }

        // Rotate access token (and optionally refresh token)
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

        // Also allow logging out by refresh token for safety
        if ($request->filled('refreshToken')) {
            AuthToken::where('refresh_token', $request->input('refreshToken'))->update(['revoked' => true]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Logged out',
        ]);
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
