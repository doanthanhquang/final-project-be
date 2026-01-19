<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KanbanColumnConfig extends Model
{
    use HasFactory;

    protected $table = 'kanban_columns';

    protected $fillable = [
        'user_id',
        'column_id',
        'column_name',
        'gmail_label_id',
        'position',
        'color',
    ];

    protected $casts = [
        'position' => 'integer',
    ];

    /**
     * Get the user that owns the column configuration.
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
     * Scope query ordered by position.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('position', 'asc');
    }
}
