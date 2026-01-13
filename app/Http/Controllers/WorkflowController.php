<?php

namespace App\Http\Controllers;

use App\Services\SummarizationService;
use App\Services\Workflow\SnoozeService;
use App\Services\Workflow\WorkflowService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class WorkflowController extends Controller
{
    public function __construct(
        private WorkflowService $workflowService,
        private SnoozeService $snoozeService,
        private SummarizationService $summarizationService
    ) {}

    /**
     * Get active user from request.
     */
    private function getUser(Request $request)
    {
        return $request->attributes->get('auth_user');
    }

    /**
     * GET /api/workflow/states
     * Get all workflow states for the authenticated user.
     */
    public function getWorkflowStates(Request $request)
    {
        $user = $this->getUser($request);
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        try {
            // Process expired snoozes before getting states
            $this->snoozeService->processExpiredSnoozesForUser($user->id);

            $states = $this->workflowService->getWorkflowStates($user->id);

            return response()->json([
                'success' => true,
                'data' => $states,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch workflow states: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/workflow/states/:emailId
     * Update workflow state for an email.
     */
    public function updateWorkflowState(Request $request, string $emailId)
    {
        $user = $this->getUser($request);
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $validator = Validator::make($request->all(), [
            'column_id' => 'required|string|in:inbox,todo,in_progress,done,snoozed',
            'position' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $state = $this->workflowService->moveEmail(
                $user->id,
                $emailId,
                $request->input('column_id'),
                $request->input('position')
            );

            return response()->json([
                'success' => true,
                'data' => $state,
                'message' => 'Email moved successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update workflow state: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/workflow/snooze/:emailId
     * Snooze an email until a specific time.
     */
    public function snoozeEmail(Request $request, string $emailId)
    {
        $user = $this->getUser($request);
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $validator = Validator::make($request->all(), [
            'snooze_until' => 'required_without:quick_option',
            'quick_option' => 'required_without:snooze_until|string|in:later_today,tomorrow,this_weekend,next_week',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            // Determine snooze time
            if ($request->has('quick_option')) {
                $snoozeUntil = SnoozeService::getQuickSnoozeTime($request->input('quick_option'));
            } else {
                // Parse as UTC ISO string from frontend, then convert to app timezone (GMT+7)
                $snoozeUntil = Carbon::parse($request->input('snooze_until'), 'UTC')
                    ->setTimezone(config('app.timezone'));
            }

            $state = $this->snoozeService->snoozeEmail($user->id, $emailId, $snoozeUntil);

            return response()->json([
                'success' => true,
                'data' => $state,
                'message' => 'Email snoozed successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to snooze email: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/workflow/unsnooze/:emailId
     * Unsnooze an email.
     */
    public function unsnoozeEmail(Request $request, string $emailId)
    {
        $user = $this->getUser($request);
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        try {
            $state = $this->snoozeService->unsnoozeEmail($user->id, $emailId);

            return response()->json([
                'success' => true,
                'data' => $state,
                'message' => 'Email unsnoozed successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to unsnooze email: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/workflow/initialize/:emailId
     * Initialize workflow state for an email.
     */
    public function initializeEmail(Request $request, string $emailId)
    {
        $user = $this->getUser($request);
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        try {
            $state = $this->workflowService->initializeEmail($user->id, $emailId);

            return response()->json([
                'success' => true,
                'data' => $state,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to initialize email: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/emails/:emailId/summary
     * Get or generate summary for an email.
     */
    public function getEmailSummary(Request $request, string $emailId)
    {
        $user = $this->getUser($request);
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        // Note: This endpoint will need email content from EmailController/GmailService
        // For now, return a placeholder response
        try {
            // TODO: Fetch email content and generate summary
            return response()->json([
                'success' => true,
                'message' => 'Summary endpoint - to be integrated with email fetching',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get summary: '.$e->getMessage(),
            ], 500);
        }
    }
}
