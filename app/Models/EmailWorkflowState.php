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
        if ($this->snoozed_until === null) {
            return false;
        }

        $nowInAppTimezone = now(config('app.timezone'));

        return $this->snoozed_until->greaterThan($nowInAppTimezone);
    }

    /**
     * Check if snooze period has expired.
     */
    public function isSnoozeExpired(): bool
    {
        if ($this->snoozed_until === null) {
            return false;
        }

        $nowInAppTimezone = now(config('app.timezone'));

        return $this->snoozed_until->lessThanOrEqualTo($nowInAppTimezone);
    }

    /**
     * Scope query to snoozed emails.
     */
    public function scopeSnoozed($query)
    {
        $nowInAppTimezone = now(config('app.timezone'));

        return $query->where('column_id', 'snoozed')
            ->whereNotNull('snoozed_until')
            ->where('snoozed_until', '>', $nowInAppTimezone);
    }

    /**
     * Scope query to expired snoozes.
     */
    public function scopeExpiredSnoozes($query)
    {
        $nowInAppTimezone = now(config('app.timezone'));

        return $query->where('column_id', 'snoozed')
            ->whereNotNull('snoozed_until')
            ->where('snoozed_until', '<=', $nowInAppTimezone);
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
