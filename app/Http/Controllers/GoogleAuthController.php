<?php

namespace App\Http\Controllers;

use App\Models\EmailProvider;
use App\Models\User;
use App\Services\GmailService;
use App\Services\GoogleAuthService;
use App\Services\TokenService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class GoogleAuthController extends Controller
{
    public function __construct(
        private GoogleAuthService $googleAuthService,
        private GmailService $gmailService,
        private TokenService $tokenService
    ) {}

    /**
     * Handle Google Sign-In
     * Exchange Google credential for app tokens
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function googleSignIn(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|string', // OAuth authorization code
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            // Exchange code for tokens and get user info
            $tokens = $this->googleAuthService->exchangeCodeForTokens($request->input('code'));

            // Get user info from Google using access token
            $client = new \Google\Client;
            $client->setAccessToken($tokens['access_token']);

            $oauth2 = new \Google\Service\Oauth2($client);
            $userInfo = $oauth2->userinfo->get();

            $googleId = $userInfo->id;
            $email = $userInfo->email;
            $name = $userInfo->name;
            $avatar = $userInfo->picture;

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

            $tokenData = $this->tokenService->createTokensForUser($user);

            // Set up Gmail email provider
            $emailProviderConnected = false;
            try {
                EmailProvider::updateOrCreate(
                    [
                        'user_id' => $user->id,
                        'provider_type' => 'gmail',
                    ],
                    [
                        'access_token' => $tokens['access_token'],
                        'refresh_token' => $tokens['refresh_token'] ?? null,
                        'access_token_expires_at' => now()->addSeconds($tokens['expires_in']),
                        'connected' => true,
                        'connected_at' => now(),
                    ]
                );

                $emailProviderConnected = true;
                Log::info('Gmail provider connected during sign-in', ['user_id' => $user->id]);
            } catch (\Exception $e) {
                Log::warning('Failed to connect Gmail provider during sign-in', [
                    'error' => $e->getMessage(),
                    'user_id' => $user->id,
                ]);
            }

            // Set refresh token in httpOnly cookie (server-side only)
            $response = response()->json(
                $this->tokenService->buildAuthResponse($user, $tokenData, [
                    'isNewUser' => $user->wasRecentlyCreated,
                    'emailProviderConnected' => $emailProviderConnected,
                ])
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
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred during Google authentication',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Initiate Google OAuth flow for Gmail access.
     * GET /api/auth/google/authorize
     */
    public function authorize(Request $request)
    {
        $user = $request->attributes->get('auth_user');
        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        // Generate state token for CSRF protection
        $state = Str::random(32);
        session(['google_oauth_state' => $state, 'google_oauth_user_id' => $user->id]);

        $authUrl = $this->googleAuthService->getAuthorizationUrl($state);

        return response()->json([
            'success' => true,
            'auth_url' => $authUrl,
        ]);
    }

    /**
     * Handle Google OAuth callback.
     * This is called after user authorizes Gmail access.
     * GET /api/auth/google/callback
     */
    public function callback(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|string',
            'state' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        // Verify state token
        $sessionState = session('google_oauth_state');
        $userId = session('google_oauth_user_id');

        if (! $sessionState || $sessionState !== $request->input('state') || ! $userId) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid state token',
            ], 400);
        }

        try {
            // Exchange code for tokens
            $tokens = $this->googleAuthService->exchangeCodeForTokens($request->input('code'));

            if (! $tokens['refresh_token']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to obtain refresh token. Please try again.',
                ], 400);
            }

            // Find or create email provider
            $provider = EmailProvider::where('user_id', $userId)
                ->where('provider_type', 'gmail')
                ->first();

            if (! $provider) {
                $provider = EmailProvider::create([
                    'user_id' => $userId,
                    'provider_type' => 'gmail',
                ]);
            }

            // Connect using Gmail service
            $this->gmailService->connect($provider, $tokens);

            // Clear session
            session()->forget(['google_oauth_state', 'google_oauth_user_id']);

            return response()->json([
                'success' => true,
                'message' => 'Gmail account connected successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to connect Gmail account: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Disconnect Gmail email provider.
     * POST /api/auth/google/disconnect
     */
    public function disconnect(Request $request)
    {
        $user = $request->attributes->get('auth_user');
        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        $provider = EmailProvider::where('user_id', $user->id)
            ->where('provider_type', 'gmail')
            ->where('connected', true)
            ->first();

        if (! $provider) {
            return response()->json([
                'success' => false,
                'message' => 'No Gmail provider connected',
            ], 400);
        }

        try {
            $this->gmailService->disconnect($provider);

            return response()->json([
                'success' => true,
                'message' => 'Gmail disconnected successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to disconnect Gmail: '.$e->getMessage(),
            ], 500);
        }
    }
}
