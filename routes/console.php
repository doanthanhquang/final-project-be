<?php

use App\Models\EmailEmbedding;
use App\Models\EmailProvider;
use App\Services\EmbeddingService;
use App\Services\GmailService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('emails:embed {--limit=100}', function () {
    $limit = (int) $this->option('limit');
    if ($limit <= 0) {
        $this->error('Limit must be a positive integer.');

        return;
    }

    /** @var GmailService $gmailService */
    $gmailService = app(GmailService::class);
    /** @var EmbeddingService $embeddingService */
    $embeddingService = app(EmbeddingService::class);

    $providers = EmailProvider::where('connected', true)
        ->where('provider_type', 'gmail')
        ->get();

    if ($providers->isEmpty()) {
        $this->info('No connected Gmail providers found.');

        return;
    }

    $totalProcessed = 0;
    $totalSucceeded = 0;
    $totalFailed = 0;

    foreach ($providers as $provider) {
        $this->info("Processing embeddings for user {$provider->user_id} (provider {$provider->id})...");

        // Fetch recent emails from INBOX
        $emails = $gmailService->getEmails($provider, 'INBOX', 1, $limit * 2);
        $items = $emails->items();

        if (empty($items)) {
            $this->info('No emails found in INBOX.');

            continue;
        }

        // Filter emails that do not yet have embeddings
        $candidates = [];
        foreach ($items as $email) {
            $emailId = $email['id'] ?? null;
            if (! $emailId) {
                continue;
            }

            $exists = EmailEmbedding::forUser($provider->user_id)
                ->forEmail($emailId)
                ->exists();

            if (! $exists) {
                $candidates[] = ['id' => $emailId];
            }

            if (count($candidates) >= $limit) {
                break;
            }
        }

        if (empty($candidates)) {
            $this->info('All recent emails already have embeddings.');

            continue;
        }

        $this->info('Generating embeddings for '.count($candidates).' emails...');

        $result = $embeddingService->batchGenerateForEmails($provider, $candidates, $provider->user_id);

        $this->info(sprintf(
            'User %d: processed=%d, succeeded=%d, failed=%d',
            $provider->user_id,
            $result['processed'],
            $result['succeeded'],
            $result['failed']
        ));

        $totalProcessed += $result['processed'];
        $totalSucceeded += $result['succeeded'];
        $totalFailed += $result['failed'];
    }

    $this->info(sprintf(
        'Embedding backfill complete. processed=%d, succeeded=%d, failed=%d',
        $totalProcessed,
        $totalSucceeded,
        $totalFailed
    ));
})->purpose('Generate semantic embeddings for recent emails');
