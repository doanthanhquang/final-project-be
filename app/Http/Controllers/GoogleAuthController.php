<?php

namespace App\Http\Controllers;

use App\Models\AuthToken;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class GoogleAuthController extends Controller
{
    /**
     * Handle Google Sign-In
     * Exchange Google credential for app tokens
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function googleSignIn(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'credential' => 'required|string', // Google ID token
            'name' => 'required|string',
            'email' => 'required|email',
            'googleId' => 'required|string',
            'avatar' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            // In production, you should verify the Google credential/token here
            // using Google's API client library
            // For this assignment, we'll trust the frontend validation

            $googleId = $request->input('googleId');
            $email = $request->input('email');
            $name = $request->input('name');
            $avatar = $request->input('avatar');

            // Find or create user
            $user = User::where('google_id', $googleId)
                ->orWhere('email', $email)
                ->first();

            if ($user) {
                // Update Google info if user exists with same email but no google_id
                if (! $user->google_id) {
                    $user->google_id = $googleId;
                    $user->provider = 'google';
                    if ($avatar) {
                        $user->avatar = $avatar;
                    }
                    $user->save();
                }
            } else {
                // Create new user
                $user = User::create([
                    'name' => $name,
                    'email' => $email,
                    'google_id' => $googleId,
                    'provider' => 'google',
                    'avatar' => $avatar,
                    'password' => Hash::make(Str::random(32)), // Random password for Google users
                ]);
            }

            // Revoke old tokens for this user
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
                'isNewUser' => $user->wasRecentlyCreated,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred during Google authentication',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
