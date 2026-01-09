<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

class EmailProvider extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'provider_type',
        'refresh_token',
        'access_token',
        'access_token_expires_at',
        'encrypted_credentials',
        'imap_host',
        'imap_port',
        'smtp_host',
        'smtp_port',
        'connected',
        'connected_at',
        'last_sync_at',
    ];

    protected $casts = [
        'connected' => 'boolean',
        'connected_at' => 'datetime',
        'last_sync_at' => 'datetime',
        'access_token_expires_at' => 'datetime',
    ];

    protected $hidden = [
        'refresh_token',
        'access_token',
        'encrypted_credentials',
    ];

    /**
     * Get the user that owns the email provider.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Encrypt and store IMAP credentials.
     */
    public function setImapCredentials(array $credentials): void
    {
        $this->encrypted_credentials = Crypt::encryptString(json_encode($credentials));
    }

    /**
     * Decrypt and retrieve IMAP credentials.
     */
    public function getImapCredentials(): ?array
    {
        if (! $this->encrypted_credentials) {
            return null;
        }

        try {
            return json_decode(Crypt::decryptString($this->encrypted_credentials), true);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Check if access token is expired or will expire soon (within 5 minutes).
     */
    public function isAccessTokenExpired(): bool
    {
        if (! $this->access_token_expires_at) {
            return true;
        }

        // Consider expired if expires within 5 minutes
        return $this->access_token_expires_at->subMinutes(5)->isPast();
    }
}
