<?php

namespace App\Services;

use App\Models\EmailSummary;
use App\Services\LLM\LLMServiceInterface;
use Illuminate\Support\Facades\Log;

class SummarizationService
{
    public function __construct(
        private LLMServiceInterface $llmService
    ) {}

    /**
     * Get or generate summary for an email.
     */
    public function getSummary(int $userId, string $emailId, string $emailContent): string
    {
        // Check cache first
        $cached = EmailSummary::forUser($userId)->forEmail($emailId)->first();

        if ($cached) {
            return $cached->summary_text;
        }

        // Generate new summary
        try {
            $result = $this->llmService->summarize($emailContent);

            // Cache the summary
            EmailSummary::create([
                'user_id' => $userId,
                'email_id' => $emailId,
                'summary_text' => $result['summary'],
                'model_used' => $result['model_used'],
                'tokens_used' => $result['tokens_used'],
            ]);

            return $result['summary'];
        } catch (\Exception $e) {
            Log::error('Failed to generate summary', [
                'user_id' => $userId,
                'email_id' => $emailId,
                'error' => $e->getMessage(),
            ]);

            // Return fallback: first 100 chars of content
            return $this->getFallbackSummary($emailContent);
        }
    }

    /**
     * Batch summarize multiple emails.
     */
    public function batchSummarize(int $userId, array $emails): array
    {
        $summaries = [];

        foreach ($emails as $email) {
            $emailId = $email['id'];
            $content = $this->prepareContentForSummarization($email);

            $summaries[$emailId] = $this->getSummary($userId, $emailId, $content);
        }

        return $summaries;
    }

    /**
     * Prepare email content for summarization.
     */
    private function prepareContentForSummarization(array $email): string
    {
        $subject = $email['subject'] ?? '';
        $body = $email['body_text'] ?? $email['body'] ?? '';

        return "Subject: {$subject}\n\n{$body}";
    }

    /**
     * Get fallback summary when AI fails.
     */
    private function getFallbackSummary(string $content): string
    {
        // Extract first 100 characters
        $fallback = substr($content, 0, 150);

        // Try to break at a sentence or word boundary
        $lastPeriod = strrpos($fallback, '.');
        $lastSpace = strrpos($fallback, ' ');

        if ($lastPeriod !== false && $lastPeriod > 50) {
            $fallback = substr($fallback, 0, $lastPeriod + 1);
        } elseif ($lastSpace !== false && $lastSpace > 50) {
            $fallback = substr($fallback, 0, $lastSpace).'...';
        } else {
            $fallback .= '...';
        }

        return trim($fallback);
    }

    /**
     * Regenerate summary (force refresh).
     */
    public function regenerateSummary(int $userId, string $emailId, string $emailContent): string
    {
        // Delete cached summary
        EmailSummary::forUser($userId)->forEmail($emailId)->delete();

        // Generate new summary
        return $this->getSummary($userId, $emailId, $emailContent);
    }
}
