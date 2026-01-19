<?php

namespace App\Http\Controllers;

use App\Models\EmailProvider;
use App\Services\SearchSuggestionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SearchController extends Controller
{
    public function __construct(
        private SearchSuggestionService $suggestionService
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
     * GET /api/search/suggestions
     * Get search suggestions based on partial query.
     */
    public function getSuggestions(Request $request)
    {
        $user = $request->attributes->get('auth_user');
        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        $validator = Validator::make($request->all(), [
            'query' => 'required|string|min:1|max:200',
            'limit' => 'integer|min:1|max:10',
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
            $limit = $request->input('limit', 5);

            $suggestions = $this->suggestionService->getSuggestions($provider, $query, $limit);

            return response()->json([
                'success' => true,
                'data' => $suggestions,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get suggestions: '.$e->getMessage(),
            ], 500);
        }
    }
}
