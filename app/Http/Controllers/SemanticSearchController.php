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
                return response()->json([
                    'success' => true,
                    'data' => [],
                    'pagination' => [
                        'current_page' => $page,
                        'per_page' => $limit,
                        'total' => 0,
                        'last_page' => 1,
                        'has_more' => false,
                    ],
                ]);
            }

            // Fetch full email details for matched email IDs
            $emailIds = array_column($semanticResults, 'email_id');
            $emails = [];
            $similarityScores = array_column($semanticResults, 'similarity_score', 'email_id');

            // Get emails from Gmail service
            // Note: This is a simplified approach - in production, you might want to
            // cache email data or have a more efficient batch lookup
            foreach ($emailIds as $emailId) {
                try {
                    $email = $this->gmailService->getEmailDetail($provider, $emailId);
                    if ($email) {
                        // Add fields expected by frontend
                        $email['relevance_score'] = $similarityScores[$emailId] ?? 0;
                        $email['read'] = false; // Default, could be fetched from Gmail API
                        $email['has_attachments'] = ! empty($email['attachments']);
                        $emails[] = $email;
                    }
                } catch (\Exception $e) {
                    // Skip emails that can't be fetched
                    continue;
                }
            }

            // Apply pagination
            $total = count($emails);
            $offset = ($page - 1) * $limit;
            $paginatedEmails = array_slice($emails, $offset, $limit);

            return response()->json([
                'success' => true,
                'data' => $paginatedEmails,
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $limit,
                    'total' => $total,
                    'last_page' => (int) ceil($total / $limit),
                    'has_more' => ($offset + $limit) < $total,
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
