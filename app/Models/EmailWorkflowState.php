<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailWorkflowState extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'email_id',
        'column_id',
        'position',
        'snoozed_until',
        'previous_column_id',
    ];

    protected $casts = [
        'position' => 'integer',
        'snoozed_until' => 'datetime',
    ];

    /**
     * Get the user that owns the workflow state.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Check if email is currently snoozed.
     */
    public function isSnoozed(): bool
    {
        return $this->snoozed_until !== null && $this->snoozed_until->isFuture();
    }

    /**
     * Check if snooze period has expired.
     */
    public function isSnoozeExpired(): bool
    {
        return $this->snoozed_until !== null && $this->snoozed_until->isPast();
    }

    /**
     * Scope query to snoozed emails.
     */
    public function scopeSnoozed($query)
    {
        return $query->whereNotNull('snoozed_until')->where('snoozed_until', '>', now());
    }

    /**
     * Scope query to expired snoozes.
     */
    public function scopeExpiredSnoozes($query)
    {
        return $query->whereNotNull('snoozed_until')->where('snoozed_until', '<=', now());
    }

    /**
     * Scope query to a specific column.
     */
    public function scopeInColumn($query, string $columnId)
    {
        return $query->where('column_id', $columnId);
    }

    /**
     * Scope query to a specific user.
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }
}
