<?php

namespace App\Http\Controllers;

use App\Models\EmailProvider;
use App\Services\GmailService;
use App\Services\KanbanConfigService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class KanbanConfigController extends Controller
{
    public function __construct(
        private KanbanConfigService $kanbanConfigService,
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
     * GET /api/kanban/columns
     * Get all Kanban columns for the authenticated user.
     */
    public function getColumns(Request $request)
    {
        $user = $request->attributes->get('auth_user');
        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        try {
            $columns = $this->kanbanConfigService->getColumns($user->id);

            return response()->json([
                'success' => true,
                'data' => $columns,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch columns: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/kanban/columns
     * Create a new Kanban column.
     */
    public function createColumn(Request $request)
    {
        $user = $request->attributes->get('auth_user');
        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        $validator = Validator::make($request->all(), [
            'column_name' => 'required|string|min:1|max:255',
            'gmail_label_id' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $columnName = $request->input('column_name');
            $gmailLabelId = $request->input('gmail_label_id');

            $column = $this->kanbanConfigService->createColumn($user->id, $columnName, $gmailLabelId);

            return response()->json([
                'success' => true,
                'data' => $column,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create column: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * PUT /api/kanban/columns/{columnId}
     * Update a Kanban column (rename or update other properties).
     */
    public function updateColumn(Request $request, string $columnId)
    {
        $user = $request->attributes->get('auth_user');
        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        $validator = Validator::make($request->all(), [
            'column_name' => 'sometimes|string|min:1|max:255',
            'gmail_label_id' => 'nullable|string|max:255',
            'color' => 'nullable|string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $column = null;

            if ($request->has('column_name')) {
                $column = $this->kanbanConfigService->renameColumn($user->id, $columnId, $request->input('column_name'));
            }

            if ($request->has('gmail_label_id')) {
                $column = $this->kanbanConfigService->updateGmailLabelMapping(
                    $user->id,
                    $columnId,
                    $request->input('gmail_label_id')
                );
            }

            if (! $column) {
                return response()->json([
                    'success' => false,
                    'message' => 'No valid fields to update',
                ], 422);
            }

            return response()->json([
                'success' => true,
                'data' => $column,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update column: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * DELETE /api/kanban/columns/{columnId}
     * Delete a Kanban column.
     */
    public function deleteColumn(Request $request, string $columnId)
    {
        $user = $request->attributes->get('auth_user');
        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        try {
            $this->kanbanConfigService->deleteColumn($user->id, $columnId);

            return response()->json([
                'success' => true,
                'message' => 'Column deleted successfully',
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete column: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/kanban/columns/reorder
     * Reorder Kanban columns.
     */
    public function reorderColumns(Request $request)
    {
        $user = $request->attributes->get('auth_user');
        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        $validator = Validator::make($request->all(), [
            'column_ids' => 'required|array|min:1',
            'column_ids.*' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $columnIds = $request->input('column_ids');
            $this->kanbanConfigService->reorderColumns($user->id, $columnIds);

            // Return updated columns
            $columns = $this->kanbanConfigService->getColumns($user->id);

            return response()->json([
                'success' => true,
                'data' => $columns,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to reorder columns: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/kanban/gmail-labels
     * Get available Gmail labels for mapping.
     */
    public function getGmailLabels(Request $request)
    {
        $user = $request->attributes->get('auth_user');
        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        $provider = $this->getActiveProvider($request);
        if (! $provider) {
            return response()->json([
                'success' => false,
                'message' => 'No email provider connected',
            ], 400);
        }

        try {
            $labels = $this->gmailService->listGmailLabels($provider);

            return response()->json([
                'success' => true,
                'data' => $labels,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch Gmail labels: '.$e->getMessage(),
            ], 500);
        }
    }
}
