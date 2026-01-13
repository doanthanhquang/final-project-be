<?php

namespace App\Services\Workflow;

use App\Models\EmailWorkflowState;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class SnoozeService
{
    public function __construct(
        private WorkflowService $workflowService
    ) {}

    /**
     * Snooze an email until a specific time.
     */
    public function snoozeEmail(int $userId, string $emailId, Carbon $snoozeUntil): EmailWorkflowState
    {
        // Validate snooze time is in future
        if ($snoozeUntil->isPast()) {
            throw new \InvalidArgumentException('Snooze time must be in the future');
        }

        // Get or create workflow state
        $state = $this->workflowService->getEmailState($userId, $emailId);

        if (! $state) {
            $state = $this->workflowService->initializeEmail($userId, $emailId);
        }

        // Store previous column if moving to snoozed
        $previousColumn = $state->column_id !== WorkflowService::COLUMN_SNOOZED
            ? $state->column_id
            : $state->previous_column_id;

        // Update state
        $state->update([
            'column_id' => WorkflowService::COLUMN_SNOOZED,
            'snoozed_until' => $snoozeUntil,
            'previous_column_id' => $previousColumn,
        ]);

        return $state->fresh();
    }

    /**
     * Unsnooze an email (manual unsnooze).
     */
    public function unsnoozeEmail(int $userId, string $emailId): EmailWorkflowState
    {
        $state = EmailWorkflowState::forUser($userId)
            ->where('email_id', $emailId)
            ->firstOrFail();

        // Determine target column
        $targetColumn = $state->previous_column_id ?? WorkflowService::COLUMN_INBOX;

        // Move back to previous column
        $state = $this->workflowService->moveEmail($userId, $emailId, $targetColumn);

        // Clear snooze data
        $state->update([
            'snoozed_until' => null,
            'previous_column_id' => null,
        ]);

        return $state->fresh();
    }

    /**
     * Process expired snoozes (called by scheduled task).
     */
    public function processExpiredSnoozes(): int
    {
        $expiredStates = EmailWorkflowState::expiredSnoozes()->get();

        foreach ($expiredStates as $state) {
            try {
                $this->restoreEmail($state);
            } catch (\Exception $e) {
                \Log::error('Failed to restore snoozed email', [
                    'state_id' => $state->id,
                    'email_id' => $state->email_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $expiredStates->count();
    }

    /**
     * Restore a snoozed email to its previous column.
     */
    private function restoreEmail(EmailWorkflowState $state): void
    {
        $targetColumn = $state->previous_column_id ?? WorkflowService::COLUMN_INBOX;

        // Move to target column
        $this->workflowService->moveEmail(
            $state->user_id,
            $state->email_id,
            $targetColumn
        );

        // Clear snooze data
        $state->update([
            'snoozed_until' => null,
            'previous_column_id' => null,
        ]);
    }

    /**
     * Get all snoozed emails for a user.
     */
    public function getSnoozedEmails(int $userId): Collection
    {
        return EmailWorkflowState::forUser($userId)
            ->snoozed()
            ->orderBy('snoozed_until')
            ->get();
    }

    /**
     * Calculate snooze time for quick options.
     */
    public static function getQuickSnoozeTime(string $option): Carbon
    {
        return match ($option) {
            'later_today' => now()->addHours(4),
            'tomorrow' => now()->addDay()->setTime(8, 0),
            'this_weekend' => now()->next('Saturday')->setTime(9, 0),
            'next_week' => now()->next('Monday')->setTime(9, 0),
            default => throw new \InvalidArgumentException("Invalid snooze option: {$option}"),
        };
    }
}
