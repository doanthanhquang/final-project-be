<?php

namespace App\Jobs;

use App\Models\EmailProvider;
use App\Services\EmbeddingService;
use App\Services\GmailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateEmailEmbeddingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $providerId,
        public int $userId,
        public string $emailId
    ) {}

    /**
     * Execute the job.
     */
    public function handle(EmbeddingService $embeddingService, GmailService $gmailService): void
    {
        $provider = EmailProvider::find($this->providerId);

        if (! $provider || ! $provider->connected || $provider->user_id !== $this->userId) {
            Log::warning('GenerateEmailEmbeddingJob skipped: invalid provider or user mismatch', [
                'provider_id' => $this->providerId,
                'user_id' => $this->userId,
                'email_id' => $this->emailId,
            ]);

            return;
        }

        try {
            $emailDetail = $gmailService->getEmailDetail($provider, $this->emailId);

            $embeddingService->generateAndStoreForEmail(
                $this->userId,
                $this->emailId,
                $emailDetail ?? []
            );
        } catch (\Throwable $e) {
            Log::error('GenerateEmailEmbeddingJob failed', [
                'provider_id' => $this->providerId,
                'user_id' => $this->userId,
                'email_id' => $this->emailId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
