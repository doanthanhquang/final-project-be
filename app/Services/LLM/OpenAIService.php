<?php

namespace App\Services\LLM;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAIService implements LLMServiceInterface
{
    private string $apiKey;

    private string $baseUrl = 'https://api.openai.com/v1';

    public function __construct()
    {
        $this->apiKey = config('services.openai.api_key', '');
    }

    /**
     * Generate a concise summary of email content.
     */
    public function summarize(string $content): array
    {
        $model = config('services.openai.model', 'gpt-4o-mini');

        // Truncate content if too long (GPT-4o-mini has token limits)
        $maxChars = 30000; // Approximately 8000 tokens
        if (strlen($content) > $maxChars) {
            $content = substr($content, 0, $maxChars).'... [truncated]';
        }

        try {
            $response = $this->makeRequest($model, $content);

            $summary = $this->extractTextFromResponse($response);

            // Sanitize summary
            $summary = $this->sanitizeSummary($summary);

            // Estimate tokens used
            $tokensUsed = $response['usage']['total_tokens'] ?? $this->estimateTokens($content.$summary);

            return [
                'summary' => $summary,
                'tokens_used' => $tokensUsed,
                'model_used' => $model,
            ];
        } catch (\Exception $e) {
            Log::error('OpenAI summarization failed', [
                'error' => $e->getMessage(),
                'content_length' => strlen($content),
            ]);
            throw $e;
        }
    }

    /**
     * Generate embedding vector for email content.
     */
    public function generateEmbedding(string $content): array
    {
        $embeddingModel = config('services.openai.embedding_model', 'text-embedding-3-small');

        // Truncate content if too long
        $maxChars = 20000;
        if (strlen($content) > $maxChars) {
            $content = substr($content, 0, $maxChars);
        }

        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'Authorization' => 'Bearer '.$this->apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->post("{$this->baseUrl}/embeddings", [
                    'model' => $embeddingModel,
                    'input' => $content,
                ]);

            if (! $response->successful()) {
                throw new \Exception('OpenAI embedding API error: '.$response->body());
            }

            $data = $response->json();
            $embedding = $data['data'][0]['embedding'] ?? [];

            if (empty($embedding)) {
                throw new \Exception('Empty embedding returned from OpenAI');
            }

            return [
                'embedding' => $embedding,
                'dimensions' => count($embedding),
                'model_used' => $embeddingModel,
            ];
        } catch (\Exception $e) {
            Log::error('OpenAI embedding generation failed', [
                'error' => $e->getMessage(),
                'content_length' => strlen($content),
            ]);
            throw $e;
        }
    }

    /**
     * Make API request to OpenAI with retry logic.
     */
    private function makeRequest(string $model, string $content, int $retries = 3): array
    {
        $attempt = 0;

        while ($attempt < $retries) {
            try {
                $response = Http::timeout(30)
                    ->withHeaders([
                        'Authorization' => 'Bearer '.$this->apiKey,
                        'Content-Type' => 'application/json',
                    ])
                    ->post("{$this->baseUrl}/chat/completions", [
                        'model' => $model,
                        'messages' => [
                            [
                                'role' => 'system',
                                'content' => 'You are a helpful assistant that summarizes emails concisely. Provide summaries in 2-3 sentences focusing on key points and action items. Be direct and specific.',
                            ],
                            [
                                'role' => 'user',
                                'content' => "Summarize this email:\n\n".$content,
                            ],
                        ],
                        'temperature' => 0.3,
                        'max_tokens' => 150,
                    ]);

                if ($response->successful()) {
                    return $response->json();
                }

                // Check for rate limit
                if ($response->status() === 429) {
                    $attempt++;
                    $waitTime = min(2 ** $attempt, 8); // Exponential backoff: 2s, 4s, 8s
                    Log::warning('OpenAI rate limit hit, retrying', ['attempt' => $attempt, 'wait' => $waitTime]);
                    sleep($waitTime);

                    continue;
                }

                throw new \Exception('OpenAI API error: '.$response->body());
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                $attempt++;
                if ($attempt >= $retries) {
                    throw new \Exception('OpenAI API connection failed after retries: '.$e->getMessage());
                }
                sleep(2 ** $attempt);
            }
        }

        throw new \Exception('OpenAI API failed after '.$retries.' retries');
    }

    /**
     * Extract text from OpenAI response.
     */
    private function extractTextFromResponse(array $response): string
    {
        $text = $response['choices'][0]['message']['content'] ?? '';

        if (empty($text)) {
            throw new \Exception('No text in OpenAI response');
        }

        return trim($text);
    }

    /**
     * Sanitize summary text.
     */
    private function sanitizeSummary(string $summary): string
    {
        // Remove markdown formatting
        $summary = preg_replace('/[*_#`]/', '', $summary);

        // Remove excessive newlines
        $summary = preg_replace('/\n+/', ' ', $summary);

        // Remove extra whitespace
        $summary = preg_replace('/\s+/', ' ', $summary);

        // Trim and limit length
        $summary = trim($summary);
        if (strlen($summary) > 500) {
            $summary = substr($summary, 0, 497).'...';
        }

        // Escape HTML special characters for safe display
        return htmlspecialchars($summary, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Estimate token count (rough approximation).
     */
    private function estimateTokens(string $text): int
    {
        // Rough estimate: 1 token ≈ 4 characters for English
        return (int) ceil(strlen($text) / 4);
    }
}
