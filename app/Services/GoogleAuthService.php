<?php

namespace App\Services;

use Google\Client;
use Illuminate\Support\Facades\Log;

class GoogleAuthService
{
    /**
     * Get Google OAuth authorization URL.
     */
    public function getAuthorizationUrl(string $state): string
    {
        $client = new Client;
        $client->setClientId(config('services.google.client_id'));
        $client->setClientSecret(config('services.google.client_secret'));
        $client->setRedirectUri(config('services.google.redirect_uri'));
        $client->setScopes(config('services.google.scopes'));
        $client->setAccessType('offline');
        $client->setPrompt('consent'); // Force consent to get refresh token
        $client->setState($state);

        return $client->createAuthUrl();
    }

    /**
     * Exchange authorization code for tokens.
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int}
     */
    public function exchangeCodeForTokens(string $code): array
    {
        $client = new Client;
        $client->setClientId(config('services.google.client_id'));
        $client->setClientSecret(config('services.google.client_secret'));

        // When using frontend popup flow (useGoogleLogin with auth-code),
        // we need to tell Google we're using postmessage instead of a redirect URI
        $client->setRedirectUri('postmessage');

        $accessToken = $client->fetchAccessTokenWithAuthCode($code);

        if (isset($accessToken['error'])) {
            Log::error('Google token exchange failed', [
                'error' => $accessToken['error'],
                'error_description' => $accessToken['error_description'] ?? null,
                'code_preview' => substr($code, 0, 10).'...',
            ]);
            throw new \Exception('Failed to exchange code: '.$accessToken['error'].'. '.(isset($accessToken['error_description']) ? $accessToken['error_description'] : ''));
        }

        return [
            'access_token' => $accessToken['access_token'],
            'refresh_token' => $accessToken['refresh_token'] ?? null,
            'expires_in' => $accessToken['expires_in'] ?? 3600,
        ];
    }

    /**
     * Revoke a refresh token.
     */
    public function revokeToken(string $refreshToken): bool
    {
        try {
            $client = new Client;
            $client->setClientId(config('services.google.client_id'));
            $client->setClientSecret(config('services.google.client_secret'));
            $client->revokeToken($refreshToken);

            return true;
        } catch (\Exception $e) {
            Log::warning('Failed to revoke Google token', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
