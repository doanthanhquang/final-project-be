<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailSummary extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'email_id',
        'summary_text',
        'model_used',
        'tokens_used',
    ];

    protected $casts = [
        'tokens_used' => 'integer',
    ];

    /**
     * Get the user that owns the summary.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope query to a specific user.
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope query to a specific email.
     */
    public function scopeForEmail($query, string $emailId)
    {
        return $query->where('email_id', $emailId);
    }
}
