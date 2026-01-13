<?php

namespace App\Services\LLM;

interface LLMServiceInterface
{
    /**
     * Generate a concise summary of email content.
     *
     * @param  string  $content  Email content (subject + body)
     * @return array{summary: string, tokens_used: int, model_used: string}
     *
     * @throws \Exception if API call fails
     */
    public function summarize(string $content): array;

    /**
     * Generate embedding vector for email content.
     *
     * @param  string  $content  Email content (subject + body)
     * @return array{embedding: array, dimensions: int, model_used: string}
     *
     * @throws \Exception if API call fails
     */
    public function generateEmbedding(string $content): array;
}
