<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailEmbedding extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'email_id',
        'embedding_vector',
        'model_used',
        'dimensions',
    ];

    protected $casts = [
        'embedding_vector' => 'array', // Cast JSON to array
        'dimensions' => 'integer',
    ];

    /**
     * Get the user that owns the embedding.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Compute cosine similarity between this embedding and another vector.
     */
    public function cosineSimilarity(array $vector): float
    {
        $embedding = $this->embedding_vector;

        // Validate dimensions match
        if (count($embedding) !== count($vector)) {
            throw new \InvalidArgumentException('Vector dimensions must match');
        }

        // Compute dot product
        $dotProduct = 0.0;
        for ($i = 0; $i < count($embedding); $i++) {
            $dotProduct += $embedding[$i] * $vector[$i];
        }

        // Compute magnitudes
        $magnitudeA = sqrt(array_sum(array_map(fn ($x) => $x * $x, $embedding)));
        $magnitudeB = sqrt(array_sum(array_map(fn ($x) => $x * $x, $vector)));

        // Avoid division by zero
        if ($magnitudeA == 0 || $magnitudeB == 0) {
            return 0.0;
        }

        return $dotProduct / ($magnitudeA * $magnitudeB);
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
