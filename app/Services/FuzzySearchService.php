<?php

namespace App\Services;

class FuzzySearchService
{
    /**
     * Minimum similarity threshold (0-100)
     * Results below this threshold will be filtered out.
     */
    private float $minSimilarityThreshold = 60.0;

    /**
     * Search emails with fuzzy matching.
     *
     * @param  array  $emails  Array of email data with 'subject', 'from', 'date', 'read', 'id' keys
     * @param  string  $query  Search query
     * @return array Array of emails with 'relevance_score' added, sorted by relevance
     */
    public function search(array $emails, string $query): array
    {
        if (empty($query)) {
            return $emails;
        }

        $queryLower = mb_strtolower($query);
        $results = [];

        foreach ($emails as $email) {
            $subject = mb_strtolower($email['subject'] ?? '');
            $from = mb_strtolower($email['from'] ?? '');

            // Extract sender name and email from "Name <email@example.com>" format
            $senderName = '';
            $senderEmail = $from;
            if (preg_match('/^(.+?)\s*<(.+?)>$/', $from, $matches)) {
                $senderName = mb_strtolower(trim($matches[1], '"'));
                $senderEmail = mb_strtolower(trim($matches[2]));
            }

            // Calculate relevance scores for different fields
            $subjectScore = $this->calculateRelevance($subject, $queryLower);
            $senderNameScore = $senderName ? $this->calculateRelevance($senderName, $queryLower) : 0;
            $senderEmailScore = $this->calculateRelevance($senderEmail, $queryLower);

            // Take the highest score (best match)
            $maxScore = max($subjectScore, $senderNameScore, $senderEmailScore);

            // Only include results above threshold
            if ($maxScore >= $this->minSimilarityThreshold) {
                $email['relevance_score'] = round($maxScore, 2);
                $results[] = $email;
            }
        }

        // Sort by relevance score (highest first)
        usort($results, function ($a, $b) {
            return ($b['relevance_score'] ?? 0) <=> ($a['relevance_score'] ?? 0);
        });

        return $results;
    }

    /**
     * Calculate relevance score for a text field against a query.
     *
     * Scoring:
     * - Exact match: 100
     * - High similarity (>=80%): 80-99
     * - Partial match (contains query): 60-79
     * - Low similarity (>=60%): 40-59
     *
     * @param  string  $text  Text to search in
     * @param  string  $query  Search query
     * @return float Relevance score (0-100)
     */
    private function calculateRelevance(string $text, string $query): float
    {
        // Exact match (case-insensitive)
        if ($text === $query) {
            return 100.0;
        }

        // Check if query is contained in text (partial match)
        $containsQuery = mb_strpos($text, $query) !== false;
        if ($containsQuery) {
            // Calculate similarity based on position and length
            $position = mb_strpos($text, $query);
            $textLength = mb_strlen($text);
            $queryLength = mb_strlen($query);

            // If query is at the start, give higher score
            $positionScore = $position === 0 ? 1.0 : max(0.7, 1.0 - ($position / $textLength));

            // If query is a significant portion of text, give higher score
            $lengthRatio = $queryLength / max($textLength, 1);
            $lengthScore = min(1.0, $lengthRatio * 1.5);

            // Base score for partial match: 60-79
            $baseScore = 60.0 + ($positionScore * 10.0) + ($lengthScore * 9.0);

            return min(79.0, $baseScore);
        }

        // Calculate Levenshtein distance similarity
        $similarity = $this->calculateSimilarity($text, $query);

        if ($similarity >= 80.0) {
            // High similarity: 80-99
            return 80.0 + (($similarity - 80.0) / 20.0) * 19.0;
        } elseif ($similarity >= 60.0) {
            // Low similarity: 40-59
            return 40.0 + (($similarity - 60.0) / 20.0) * 19.0;
        }

        // Below threshold
        return 0.0;
    }

    /**
     * Calculate similarity percentage using Levenshtein distance.
     *
     * @param  string  $text1  First text
     * @param  string  $text2  Second text
     * @return float Similarity percentage (0-100)
     */
    private function calculateSimilarity(string $text1, string $text2): float
    {
        $maxLength = max(mb_strlen($text1), mb_strlen($text2));
        if ($maxLength === 0) {
            return 100.0;
        }

        $distance = $this->levenshteinDistance($text1, $text2);
        $similarity = (1 - ($distance / $maxLength)) * 100;

        return max(0.0, $similarity);
    }

    /**
     * Calculate Levenshtein distance between two strings.
     *
     * @param  string  $str1  First string
     * @param  string  $str2  Second string
     * @return int Levenshtein distance
     */
    private function levenshteinDistance(string $str1, string $str2): int
    {
        $len1 = mb_strlen($str1);
        $len2 = mb_strlen($str2);

        // Use PHP's built-in function if available
        if (function_exists('levenshtein')) {
            return levenshtein($str1, $str2);
        }

        // Manual implementation for multi-byte strings
        if ($len1 === 0) {
            return $len2;
        }
        if ($len2 === 0) {
            return $len1;
        }

        $matrix = [];
        for ($i = 0; $i <= $len1; $i++) {
            $matrix[$i] = [$i];
        }
        for ($j = 0; $j <= $len2; $j++) {
            $matrix[0][$j] = $j;
        }

        for ($i = 1; $i <= $len1; $i++) {
            for ($j = 1; $j <= $len2; $j++) {
                $cost = mb_substr($str1, $i - 1, 1) === mb_substr($str2, $j - 1, 1) ? 0 : 1;
                $matrix[$i][$j] = min(
                    $matrix[$i - 1][$j] + 1,      // deletion
                    $matrix[$i][$j - 1] + 1,      // insertion
                    $matrix[$i - 1][$j - 1] + $cost // substitution
                );
            }
        }

        return $matrix[$len1][$len2];
    }

    /**
     * Set minimum similarity threshold.
     *
     * @param  float  $threshold  Threshold value (0-100)
     */
    public function setMinSimilarityThreshold(float $threshold): self
    {
        $this->minSimilarityThreshold = max(0.0, min(100.0, $threshold));

        return $this;
    }

    /**
     * Get minimum similarity threshold.
     */
    public function getMinSimilarityThreshold(): float
    {
        return $this->minSimilarityThreshold;
    }
}
