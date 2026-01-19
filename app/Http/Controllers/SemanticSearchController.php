<?php

namespace App\Http\Controllers;

use App\Models\EmailProvider;
use App\Services\GmailService;
use App\Services\SemanticSearchService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SemanticSearchController extends Controller
{
    public function __construct(
        private SemanticSearchService $semanticSearchService,
        private GmailService $gmailService
    ) {}

    /**
     * Get active email provider for authenticated user.
     */
    private function getActiveProvider(Request $request): ?EmailProvider
    {
        $user = $request->attributes->get('auth_user');
        if (! $user) {
            return null;
        }

        return EmailProvider::where('user_id', $user->id)
            ->where('connected', true)
            ->where('provider_type', 'gmail')
            ->first();
    }

    /**
     * POST /api/search/semantic
     * Search emails using semantic similarity.
     */
    public function search(Request $request)
    {
        $user = $request->attributes->get('auth_user');
        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        $validator = Validator::make($request->all(), [
            'query' => 'required|string|min:1|max:500',
            'limit' => 'integer|min:1|max:100',
            'threshold' => 'numeric|min:0|max:1',
            'page' => 'integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $provider = $this->getActiveProvider($request);
        if (! $provider) {
            return response()->json([
                'success' => false,
                'message' => 'No email provider connected',
            ], 400);
        }

        try {
            $startTime = microtime(true);

            $query = $request->input('query');
            $limit = $request->input('limit', 50);
            $threshold = $request->input('threshold');
            $page = $request->input('page', 1);

            // Perform semantic search
            $semanticResults = $this->semanticSearchService->search(
                $user->id,
                $query,
                $limit,
                $threshold
            );

            if (empty($semanticResults)) {
                $tookMs = (int) ((microtime(true) - $startTime) * 1000);

                return response()->json([
                    'success' => true,
                    'data' => [],
                    'meta' => [
                        'took_ms' => $tookMs,
                        'model' => config('services.openai.embedding_model', 'text-embedding-3-small'),
                    ],
                ]);
            }

            // Fetch full email details for matched email IDs
            $emailIds = array_column($semanticResults, 'email_id');
            $results = [];
            $similarityScores = array_column($semanticResults, 'similarity_score', 'email_id');

            // Get emails from Gmail service
            // Note: This is a simplified approach - in production, you might want to
            // cache email data or have a more efficient batch lookup
            foreach ($emailIds as $emailId) {
                try {
                    $email = $this->gmailService->getEmailDetail($provider, $emailId);
                    if ($email) {
                        $score = $similarityScores[$emailId] ?? 0.0;

                        // Build displayable "from" string
                        $from = $email['from'] ?? null;
                        if (is_array($from)) {
                            $name = $from['name'] ?? '';
                            $address = $from['email'] ?? '';
                            $fromDisplay = $name !== '' ? sprintf('%s <%s>', $name, $address) : $address;
                        } else {
                            $fromDisplay = (string) $from;
                        }

                        // Build snippet/summary from body
                        $bodyText = $email['body_text'] ?? null;
                        $bodyHtml = $email['body_html'] ?? null;
                        $rawSnippet = $bodyText ?: ($bodyHtml ? strip_tags($bodyHtml) : '');
                        $snippet = mb_substr(trim($rawSnippet), 0, 200);

                        $results[] = [
                            'id' => $email['id'],
                            'gmail_message_id' => $email['id'],
                            'subject' => $email['subject'],
                            'from' => $fromDisplay,
                            'date' => $email['date'],
                            'snippet' => $snippet,
                            'summary' => $snippet,
                            // Scores
                            'score' => $score,
                            'relevance_score' => round($score * 100),
                            // Extra metadata used by frontend
                            'read' => false,
                            'has_attachments' => ! empty($email['attachments']),
                        ];
                    }
                } catch (\Exception $e) {
                    // Skip emails that can't be fetched
                    continue;
                }
            }

            $tookMs = (int) ((microtime(true) - $startTime) * 1000);
            $modelUsed = $semanticResults[0]['model_used'] ?? config('services.openai.embedding_model', 'text-embedding-3-small');

            return response()->json([
                'success' => true,
                'data' => $results,
                'meta' => [
                    'took_ms' => $tookMs,
                    'model' => $modelUsed,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Semantic search failed: '.$e->getMessage(),
            ], 500);
        }
    }
}
