<?php

namespace App\Services;

use App\Models\EmailEmbedding;
use App\Services\LLM\LLMServiceInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SemanticSearchService
{
    private LLMServiceInterface $llmService;

    private float $similarityThreshold = 0.5;

    public function __construct(LLMServiceInterface $llmService)
    {
        $this->llmService = $llmService;
    }

    /**
     * Search emails using semantic similarity.
     *
     * @param  int  $userId  User ID
     * @param  string  $query  Search query text
     * @param  int  $limit  Maximum number of results
     * @param  float|null  $threshold  Minimum similarity threshold (0-1)
     * @return array Array of emails with similarity scores, sorted by relevance
     */
    public function search(int $userId, string $query, int $limit = 50, ?float $threshold = null): array
    {
        if (empty($query)) {
            return [];
        }

        $threshold = $threshold ?? $this->similarityThreshold;

        // Generate query embedding
        $queryEmbedding = $this->generateQueryEmbedding($query);

        // Get all user's email embeddings
        $embeddings = EmailEmbedding::forUser($userId)->get();

        if ($embeddings->isEmpty()) {
            return [];
        }

        // Calculate similarity scores
        $results = [];
        foreach ($embeddings as $embedding) {
            $similarity = $this->calculateCosineSimilarity(
                $queryEmbedding['embedding'],
                $embedding->embedding_vector
            );

            if ($similarity >= $threshold) {
                $results[] = [
                    'email_id' => $embedding->email_id,
                    'similarity_score' => round($similarity, 4),
                    'model_used' => $embedding->model_used,
                ];
            }
        }

        // Sort by similarity score (highest first)
        usort($results, function ($a, $b) {
            return $b['similarity_score'] <=> $a['similarity_score'];
        });

        // Limit results
        return array_slice($results, 0, $limit);
    }

    /**
     * Generate embedding for search query with caching.
     *
     * @param  string  $query  Search query
     * @return array Embedding data with 'embedding', 'dimensions', 'model_used'
     */
    private function generateQueryEmbedding(string $query): array
    {
        // Cache query embeddings for 1 hour
        $cacheKey = 'query_embedding_'.md5($query);

        return Cache::remember($cacheKey, 3600, function () use ($query) {
            try {
                return $this->llmService->generateEmbedding($query);
            } catch (\Exception $e) {
                Log::error('Failed to generate query embedding', [
                    'query' => $query,
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }
        });
    }

    /**
     * Calculate cosine similarity between two vectors.
     *
     * @param  array  $vectorA  First vector
     * @param  array  $vectorB  Second vector
     * @return float Cosine similarity (0-1)
     */
    private function calculateCosineSimilarity(array $vectorA, array $vectorB): float
    {
        // Validate dimensions match
        if (count($vectorA) !== count($vectorB)) {
            Log::warning('Vector dimension mismatch', [
                'dim_a' => count($vectorA),
                'dim_b' => count($vectorB),
            ]);

            return 0.0;
        }

        // Compute dot product
        $dotProduct = 0.0;
        for ($i = 0; $i < count($vectorA); $i++) {
            $dotProduct += $vectorA[$i] * $vectorB[$i];
        }

        // Compute magnitudes
        $magnitudeA = sqrt(array_sum(array_map(fn ($x) => $x * $x, $vectorA)));
        $magnitudeB = sqrt(array_sum(array_map(fn ($x) => $x * $x, $vectorB)));

        // Avoid division by zero
        if ($magnitudeA == 0 || $magnitudeB == 0) {
            return 0.0;
        }

        return $dotProduct / ($magnitudeA * $magnitudeB);
    }

    /**
     * Set similarity threshold.
     *
     * @param  float  $threshold  Threshold value (0-1)
     */
    public function setSimilarityThreshold(float $threshold): self
    {
        $this->similarityThreshold = max(0.0, min(1.0, $threshold));

        return $this;
    }

    /**
     * Get similarity threshold.
     */
    public function getSimilarityThreshold(): float
    {
        return $this->similarityThreshold;
    }

    /**
     * Generate embeddings for existing emails in batch.
     *
     * @param  int  $userId  User ID
     * @param  int  $batchSize  Number of emails to process per batch
     * @return array Progress information
     */
    public function generateBatchEmbeddings(int $userId, int $batchSize = 10): array
    {
        // This would be called from a background job
        // For now, return structure for future implementation
        return [
            'user_id' => $userId,
            'batch_size' => $batchSize,
            'status' => 'pending',
        ];
    }
}
