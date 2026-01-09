<?php

namespace App\Services;

use App\Models\AuthToken;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Str;

class TokenService
{
    /**
     * Create authentication tokens for a user.
     * Revokes all existing tokens before creating new ones.
     */
    public function createTokensForUser(User $user): array
    {
        // Revoke old tokens
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

        return [
            'token' => $token,
            'access_expires_at' => $accessExpiresAt,
            'refresh_expires_at' => $refreshExpiresAt,
        ];
    }

    /**
     * Format user data for API response.
     */
    public function formatUserResponse(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'avatar' => $user->avatar,
            'provider' => $user->provider ?? 'email',
        ];
    }

    /**
     * Build complete authentication response.
     */
    public function buildAuthResponse(User $user, array $tokenData, array $extra = []): array
    {
        return array_merge([
            'success' => true,
            'accessToken' => $tokenData['token']->access_token,
            'accessTokenExpiresAt' => $tokenData['access_expires_at']->toIso8601String(),
            'refreshToken' => $tokenData['token']->refresh_token,
            'refreshTokenExpiresAt' => $tokenData['refresh_expires_at']->toIso8601String(),
            'user' => $this->formatUserResponse($user),
        ], $extra);
    }
}
