<?php

namespace App\Services\Workflow;

use App\Models\EmailWorkflowState;
use Illuminate\Support\Collection;

class WorkflowService
{
    /**
     * Column IDs.
     */
    public const COLUMN_INBOX = 'inbox';

    public const COLUMN_TODO = 'todo';

    public const COLUMN_IN_PROGRESS = 'in_progress';

    public const COLUMN_DONE = 'done';

    public const COLUMN_SNOOZED = 'snoozed';

    public const VALID_COLUMNS = [
        self::COLUMN_INBOX,
        self::COLUMN_TODO,
        self::COLUMN_IN_PROGRESS,
        self::COLUMN_DONE,
        self::COLUMN_SNOOZED,
    ];

    /**
     * Get all workflow states for a user, grouped by column.
     */
    public function getWorkflowStates(int $userId): array
    {
        $states = EmailWorkflowState::forUser($userId)
            ->orderBy('column_id')
            ->orderBy('position')
            ->get();

        return $this->groupByColumn($states);
    }

    /**
     * Initialize workflow state for a new email.
     */
    public function initializeEmail(int $userId, string $emailId, string $columnId = self::COLUMN_INBOX): EmailWorkflowState
    {
        // Check if already exists
        $existing = EmailWorkflowState::forUser($userId)
            ->where('email_id', $emailId)
            ->first();

        if ($existing) {
            return $existing;
        }

        // Get next position in column
        $maxPosition = EmailWorkflowState::forUser($userId)
            ->inColumn($columnId)
            ->max('position');

        return EmailWorkflowState::create([
            'user_id' => $userId,
            'email_id' => $emailId,
            'column_id' => $columnId,
            'position' => ($maxPosition ?? -1) + 1,
        ]);
    }

    /**
     * Move email to a different column.
     */
    public function moveEmail(int $userId, string $emailId, string $newColumnId, ?int $newPosition = null): EmailWorkflowState
    {
        $this->validateColumn($newColumnId);

        $state = EmailWorkflowState::forUser($userId)
            ->where('email_id', $emailId)
            ->firstOrFail();

        $oldColumnId = $state->column_id;

        // If position not specified, add to end of column
        if ($newPosition === null) {
            $maxPosition = EmailWorkflowState::forUser($userId)
                ->inColumn($newColumnId)
                ->max('position');
            $newPosition = ($maxPosition ?? -1) + 1;
        }

        // Update state
        $state->update([
            'column_id' => $newColumnId,
            'position' => $newPosition,
            'previous_column_id' => $oldColumnId !== $newColumnId ? $oldColumnId : $state->previous_column_id,
        ]);

        // Reorder positions in both columns
        $this->reorderColumn($userId, $oldColumnId);
        if ($oldColumnId !== $newColumnId) {
            $this->reorderColumn($userId, $newColumnId);
        }

        return $state->fresh();
    }

    /**
     * Update position within same column.
     */
    public function updatePosition(int $userId, string $emailId, int $newPosition): EmailWorkflowState
    {
        $state = EmailWorkflowState::forUser($userId)
            ->where('email_id', $emailId)
            ->firstOrFail();

        $state->update(['position' => $newPosition]);

        // Reorder column
        $this->reorderColumn($userId, $state->column_id);

        return $state->fresh();
    }

    /**
     * Reorder positions in a column to be sequential (0, 1, 2, ...).
     */
    private function reorderColumn(int $userId, string $columnId): void
    {
        $states = EmailWorkflowState::forUser($userId)
            ->inColumn($columnId)
            ->orderBy('position')
            ->orderBy('updated_at')
            ->get();

        foreach ($states as $index => $state) {
            if ($state->position !== $index) {
                $state->update(['position' => $index]);
            }
        }
    }

    /**
     * Group workflow states by column.
     */
    private function groupByColumn(Collection $states): array
    {
        $grouped = [];

        foreach (self::VALID_COLUMNS as $column) {
            $grouped[$column] = $states->filter(fn ($state) => $state->column_id === $column)
                ->values()
                ->toArray();
        }

        return $grouped;
    }

    /**
     * Validate column ID.
     */
    private function validateColumn(string $columnId): void
    {
        if (! in_array($columnId, self::VALID_COLUMNS)) {
            throw new \InvalidArgumentException("Invalid column ID: {$columnId}");
        }
    }

    /**
     * Batch initialize emails.
     */
    public function batchInitializeEmails(int $userId, array $emailIds): array
    {
        $states = [];

        foreach ($emailIds as $emailId) {
            $states[] = $this->initializeEmail($userId, $emailId);
        }

        return $states;
    }

    /**
     * Get workflow state for a specific email.
     */
    public function getEmailState(int $userId, string $emailId): ?EmailWorkflowState
    {
        return EmailWorkflowState::forUser($userId)
            ->where('email_id', $emailId)
            ->first();
    }
}
