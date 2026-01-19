<?php

namespace App\Services;

use App\Models\EmailProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SearchSuggestionService
{
    private GmailService $gmailService;

    private int $cacheTtl = 3600; // 1 hour

    public function __construct(GmailService $gmailService)
    {
        $this->gmailService = $gmailService;
    }

    /**
     * Get search suggestions based on partial query.
     *
     * @param  EmailProvider  $provider  Email provider
     * @param  string  $partialQuery  Partial search query
     * @param  int  $limit  Maximum number of suggestions
     * @return array Array of suggestions with type and value
     */
    public function getSuggestions(EmailProvider $provider, string $partialQuery, int $limit = 5): array
    {
        if (empty($partialQuery)) {
            return [];
        }

        $partialQueryLower = mb_strtolower(trim($partialQuery));
        $cacheKey = 'suggestions_'.$provider->user_id.'_'.md5($partialQueryLower);

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($provider, $partialQueryLower, $limit) {
            $suggestions = [];

            // Get senders
            $senders = $this->getSenderSuggestions($provider, $partialQueryLower, $limit);
            $suggestions = array_merge($suggestions, $senders);

            // Get subject keywords
            $keywords = $this->getKeywordSuggestions($provider, $partialQueryLower, $limit);
            $suggestions = array_merge($suggestions, $keywords);

            // Remove duplicates and sort by relevance
            $suggestions = $this->deduplicateAndRank($suggestions, $partialQueryLower);

            // Limit results
            return array_slice($suggestions, 0, $limit);
        });
    }

    /**
     * Extract unique sender names from user's emails.
     *
     * @param  EmailProvider  $provider  Email provider
     * @param  string  $partialQuery  Partial query to match against
     * @param  int  $limit  Maximum number of suggestions
     * @return array Array of sender suggestions
     */
    private function getSenderSuggestions(EmailProvider $provider, string $partialQuery, int $limit): array
    {
        try {
            // Get recent emails from INBOX
            $emails = $this->gmailService->getEmails($provider, 'INBOX', 1, 100);
            $senders = [];

            foreach ($emails->items() as $email) {
                $from = $email['from'] ?? '';
                if (empty($from)) {
                    continue;
                }

                // Extract sender name and email
                $senderName = '';
                $senderEmail = $from;
                if (preg_match('/^(.+?)\s*<(.+?)>$/', $from, $matches)) {
                    $senderName = trim($matches[1], '"\'');
                    $senderEmail = trim($matches[2]);
                } else {
                    $senderEmail = $from;
                }

                // Check if matches partial query
                $nameLower = mb_strtolower($senderName);
                $emailLower = mb_strtolower($senderEmail);

                if (
                    ! empty($partialQuery) &&
                    mb_strpos($nameLower, $partialQuery) === false &&
                    mb_strpos($emailLower, $partialQuery) === false
                ) {
                    continue;
                }

                // Prefer name over email for display
                $displayValue = ! empty($senderName) ? $senderName : $senderEmail;
                $key = mb_strtolower($displayValue);

                if (! isset($senders[$key])) {
                    $senders[$key] = [
                        'type' => 'sender',
                        'value' => $displayValue,
                        'email' => $senderEmail,
                        'relevance' => $this->calculateRelevance($displayValue, $partialQuery),
                    ];
                }
            }

            // Sort by relevance
            usort($senders, function ($a, $b) {
                return $b['relevance'] <=> $a['relevance'];
            });

            return array_slice($senders, 0, $limit);
        } catch (\Exception $e) {
            Log::error('Failed to get sender suggestions', [
                'error' => $e->getMessage(),
                'provider_id' => $provider->id,
            ]);

            return [];
        }
    }

    /**
     * Extract subject keywords from user's emails.
     *
     * @param  EmailProvider  $provider  Email provider
     * @param  string  $partialQuery  Partial query to match against
     * @param  int  $limit  Maximum number of suggestions
     * @return array Array of keyword suggestions
     */
    private function getKeywordSuggestions(EmailProvider $provider, string $partialQuery, int $limit): array
    {
        try {
            // Get recent emails from INBOX
            $emails = $this->gmailService->getEmails($provider, 'INBOX', 1, 100);
            $keywords = [];
            $keywordCounts = [];

            foreach ($emails->items() as $email) {
                $subject = $email['subject'] ?? '';
                if (empty($subject)) {
                    continue;
                }

                // Extract words from subject
                $words = $this->extractKeywords($subject);

                foreach ($words as $word) {
                    $wordLower = mb_strtolower($word);

                    // Skip if doesn't match partial query
                    if (! empty($partialQuery) && mb_strpos($wordLower, $partialQuery) === false) {
                        continue;
                    }

                    // Skip very short words
                    if (mb_strlen($wordLower) < 3) {
                        continue;
                    }

                    if (! isset($keywordCounts[$wordLower])) {
                        $keywordCounts[$wordLower] = [
                            'value' => $word,
                            'count' => 0,
                            'relevance' => $this->calculateRelevance($wordLower, $partialQuery),
                        ];
                    }
                    $keywordCounts[$wordLower]['count']++;
                }
            }

            // Sort by count (frequency) and relevance
            usort($keywordCounts, function ($a, $b) {
                $scoreA = $a['count'] * 10 + $a['relevance'];
                $scoreB = $b['count'] * 10 + $b['relevance'];

                return $scoreB <=> $scoreA;
            });

            // Convert to suggestion format
            foreach (array_slice($keywordCounts, 0, $limit) as $keyword) {
                $keywords[] = [
                    'type' => 'keyword',
                    'value' => $keyword['value'],
                    'relevance' => $keyword['relevance'],
                ];
            }

            return $keywords;
        } catch (\Exception $e) {
            Log::error('Failed to get keyword suggestions', [
                'error' => $e->getMessage(),
                'provider_id' => $provider->id,
            ]);

            return [];
        }
    }

    /**
     * Extract keywords from text (remove common words, punctuation).
     *
     * @param  string  $text  Text to extract keywords from
     * @return array Array of keywords
     */
    private function extractKeywords(string $text): array
    {
        // Remove common email prefixes
        $text = preg_replace('/^(re|fwd?|fw):\s*/i', '', $text);

        // Remove punctuation and split into words
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);
        $words = preg_split('/\s+/', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY);

        // Filter out common stop words
        $stopWords = ['the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by', 'from', 'is', 'are', 'was', 'were', 'be', 'been', 'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would', 'should', 'could', 'may', 'might', 'must', 'can'];
        $words = array_filter($words, function ($word) use ($stopWords) {
            return ! in_array($word, $stopWords) && mb_strlen($word) >= 3;
        });

        return array_values($words);
    }

    /**
     * Calculate relevance score for a suggestion against partial query.
     *
     * @param  string  $text  Text to match
     * @param  string  $query  Partial query
     * @return float Relevance score (0-100)
     */
    private function calculateRelevance(string $text, string $query): float
    {
        if (empty($query)) {
            return 50.0; // Default relevance if no query
        }

        $textLower = mb_strtolower($text);
        $queryLower = mb_strtolower($query);

        // Exact match
        if ($textLower === $queryLower) {
            return 100.0;
        }

        // Starts with query
        if (mb_strpos($textLower, $queryLower) === 0) {
            return 90.0;
        }

        // Contains query
        if (mb_strpos($textLower, $queryLower) !== false) {
            return 70.0;
        }

        // Fuzzy match (simplified)
        $similarity = $this->calculateSimilarity($textLower, $queryLower);

        return $similarity * 50.0; // Scale to 0-50
    }

    /**
     * Calculate similarity between two strings (simplified Levenshtein-based).
     *
     * @param  string  $str1  First string
     * @param  string  $str2  Second string
     * @return float Similarity (0-1)
     */
    private function calculateSimilarity(string $str1, string $str2): float
    {
        $maxLength = max(mb_strlen($str1), mb_strlen($str2));
        if ($maxLength === 0) {
            return 1.0;
        }

        $distance = levenshtein($str1, $str2);

        return 1.0 - ($distance / $maxLength);
    }

    /**
     * Remove duplicate suggestions and rank by relevance.
     *
     * @param  array  $suggestions  Array of suggestions
     * @param  string  $query  Query for ranking
     * @return array Deduplicated and ranked suggestions
     */
    private function deduplicateAndRank(array $suggestions, string $query): array
    {
        $seen = [];
        $deduplicated = [];

        foreach ($suggestions as $suggestion) {
            $key = mb_strtolower($suggestion['value']);
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $deduplicated[] = $suggestion;
            }
        }

        // Sort by relevance
        usort($deduplicated, function ($a, $b) {
            return ($b['relevance'] ?? 0) <=> ($a['relevance'] ?? 0);
        });

        return $deduplicated;
    }
}
