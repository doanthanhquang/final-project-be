<?php

namespace App\Services;

use App\Models\EmailEmbedding;
use App\Models\EmailProvider;
use App\Services\LLM\LLMServiceInterface;
use Illuminate\Support\Facades\Log;

class EmbeddingService
{
    public function __construct(
        private LLMServiceInterface $llmService
    ) {}

    /**
     * Generate an embedding vector for arbitrary text.
     *
     * @return float[]
     */
    public function embedText(string $text): array
    {
        if (trim($text) === '') {
            return [];
        }

        $attempts = 0;
        $maxAttempts = 3;

        while ($attempts < $maxAttempts) {
            try {
                $result = $this->llmService->generateEmbedding($text);

                return $result['embedding'] ?? [];
            } catch (\Throwable $e) {
                $attempts++;

                Log::warning('EmbeddingService.embedText failed', [
                    'error' => $e->getMessage(),
                    'attempt' => $attempts,
                ]);

                if ($attempts >= $maxAttempts) {
                    throw $e;
                }

                // Exponential backoff: 1s, 2s
                sleep($attempts);
            }
        }

        return [];
    }

    /**
     * Build canonical text for an email from subject + body/snippet.
     */
    public function buildEmailText(array $email): string
    {
        $subject = $email['subject'] ?? '';
        $body = $email['body'] ?? '';
        $snippet = $email['snippet'] ?? ($email['summary'] ?? '');

        $content = $subject."\n\n";
        $content .= $body !== '' ? $body : $snippet;

        return trim($content);
    }

    /**
     * Generate and store embedding for a single email.
     *
     * @param  string  $emailId  Gmail message ID
     * @param  array  $emailData  Array including at least subject + body/snippet
     */
    public function generateAndStoreForEmail(int $userId, string $emailId, array $emailData): ?EmailEmbedding
    {
        $text = $this->buildEmailText($emailData);

        if ($text === '') {
            return null;
        }

        try {
            $embeddingResult = $this->llmService->generateEmbedding($text);

            return EmailEmbedding::updateOrCreate(
                [
                    'user_id' => $userId,
                    'email_id' => $emailId,
                ],
                [
                    'embedding_vector' => $embeddingResult['embedding'],
                    'model_used' => $embeddingResult['model_used'] ?? config('services.openai.embedding_model'),
                    'dimensions' => $embeddingResult['dimensions'] ?? count($embeddingResult['embedding'] ?? []),
                ]
            );
        } catch (\Throwable $e) {
            Log::error('Failed to generate/store email embedding', [
                'user_id' => $userId,
                'email_id' => $emailId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Batch-generate embeddings for multiple emails.
     *
     * @param  array<int, array{id:string}>  $emails  Minimal email records (id and metadata)
     * @return array{processed:int, succeeded:int, failed:int}
     */
    public function batchGenerateForEmails(EmailProvider $provider, array $emails, int $userId): array
    {
        $processed = 0;
        $succeeded = 0;
        $failed = 0;

        foreach ($emails as $emailMeta) {
            $processed++;

            try {
                // Defer to GmailService for full email content
                /** @var \App\Services\GmailService $gmailService */
                $gmailService = app(GmailService::class);

                $detail = $gmailService->getEmailDetail($provider, $emailMeta['id']);

                $stored = $this->generateAndStoreForEmail($userId, $emailMeta['id'], $detail ?? []);

                if ($stored) {
                    $succeeded++;
                } else {
                    $failed++;
                }
            } catch (\Throwable $e) {
                $failed++;

                Log::error('Batch email embedding generation failed for message', [
                    'user_id' => $userId,
                    'email_id' => $emailMeta['id'] ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'processed' => $processed,
            'succeeded' => $succeeded,
            'failed' => $failed,
        ];
    }
}
