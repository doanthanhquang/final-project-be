<?php

namespace App\Services;

use App\Models\KanbanColumnConfig;
use Illuminate\Support\Facades\DB;

class KanbanConfigService
{
    /**
     * Get all columns for a user, ordered by position.
     *
     * @param  int  $userId  User ID
     * @return array Array of column configurations
     */
    public function getColumns(int $userId): array
    {
        $columns = KanbanColumnConfig::forUser($userId)
            ->ordered()
            ->get();

        // If no columns exist, create default columns
        if ($columns->isEmpty()) {
            $this->createDefaultColumns($userId);
            $columns = KanbanColumnConfig::forUser($userId)
                ->ordered()
                ->get();
        }

        return $columns->toArray();
    }

    /**
     * Create a new column.
     *
     * @param  int  $userId  User ID
     * @param  string  $columnName  Column display name
     * @param  string|null  $gmailLabelId  Gmail label ID to map
     * @return KanbanColumnConfig Created column configuration
     */
    public function createColumn(int $userId, string $columnName, ?string $gmailLabelId = null): KanbanColumnConfig
    {
        // Generate unique column_id
        $columnId = $this->generateColumnId($columnName);

        // Get max position
        $maxPosition = KanbanColumnConfig::forUser($userId)->max('position') ?? -1;

        return KanbanColumnConfig::create([
            'user_id' => $userId,
            'column_id' => $columnId,
            'column_name' => $columnName,
            'gmail_label_id' => $gmailLabelId,
            'position' => $maxPosition + 1,
        ]);
    }

    /**
     * Rename a column.
     *
     * @param  int  $userId  User ID
     * @param  string  $columnId  Column ID
     * @param  string  $newName  New column name
     * @return KanbanColumnConfig Updated column configuration
     */
    public function renameColumn(int $userId, string $columnId, string $newName): KanbanColumnConfig
    {
        $column = KanbanColumnConfig::forUser($userId)
            ->where('column_id', $columnId)
            ->firstOrFail();

        $column->update(['column_name' => $newName]);

        return $column->fresh();
    }

    /**
     * Delete a column.
     *
     * @param  int  $userId  User ID
     * @param  string  $columnId  Column ID
     * @return bool Success status
     */
    public function deleteColumn(int $userId, string $columnId): bool
    {
        // Prevent deletion of default columns (optional safety check)
        $defaultColumns = ['inbox', 'todo', 'done', 'snoozed'];
        if (in_array($columnId, $defaultColumns)) {
            throw new \InvalidArgumentException('Cannot delete default columns');
        }

        $column = KanbanColumnConfig::forUser($userId)
            ->where('column_id', $columnId)
            ->firstOrFail();

        return $column->delete();
    }

    /**
     * Update column position.
     *
     * @param  int  $userId  User ID
     * @param  string  $columnId  Column ID
     * @param  int  $newPosition  New position
     * @return KanbanColumnConfig Updated column configuration
     */
    public function updateColumnPosition(int $userId, string $columnId, int $newPosition): KanbanColumnConfig
    {
        $column = KanbanColumnConfig::forUser($userId)
            ->where('column_id', $columnId)
            ->firstOrFail();

        $oldPosition = $column->position;

        // Update other columns' positions
        if ($newPosition > $oldPosition) {
            // Moving right/down
            KanbanColumnConfig::forUser($userId)
                ->where('position', '>', $oldPosition)
                ->where('position', '<=', $newPosition)
                ->decrement('position');
        } else {
            // Moving left/up
            KanbanColumnConfig::forUser($userId)
                ->where('position', '>=', $newPosition)
                ->where('position', '<', $oldPosition)
                ->increment('position');
        }

        $column->update(['position' => $newPosition]);

        return $column->fresh();
    }

    /**
     * Reorder multiple columns at once.
     *
     * @param  int  $userId  User ID
     * @param  array  $columnIds  Array of column IDs in desired order
     * @return bool Success status
     */
    public function reorderColumns(int $userId, array $columnIds): bool
    {
        DB::transaction(function () use ($userId, $columnIds) {
            foreach ($columnIds as $position => $columnId) {
                KanbanColumnConfig::forUser($userId)
                    ->where('column_id', $columnId)
                    ->update(['position' => $position]);
            }
        });

        return true;
    }

    /**
     * Update Gmail label mapping for a column.
     *
     * @param  int  $userId  User ID
     * @param  string  $columnId  Column ID
     * @param  string|null  $gmailLabelId  Gmail label ID (null to remove mapping)
     * @return KanbanColumnConfig Updated column configuration
     */
    public function updateGmailLabelMapping(int $userId, string $columnId, ?string $gmailLabelId): KanbanColumnConfig
    {
        $column = KanbanColumnConfig::forUser($userId)
            ->where('column_id', $columnId)
            ->firstOrFail();

        $column->update(['gmail_label_id' => $gmailLabelId]);

        return $column->fresh();
    }

    /**
     * Create default columns for a user.
     *
     * @param  int  $userId  User ID
     */
    private function createDefaultColumns(int $userId): void
    {
        $defaultColumns = [
            ['column_id' => 'inbox', 'column_name' => 'Inbox', 'position' => 0],
            ['column_id' => 'todo', 'column_name' => 'To Do', 'position' => 1],
            ['column_id' => 'in_progress', 'column_name' => 'In Progress', 'position' => 2],
            ['column_id' => 'done', 'column_name' => 'Done', 'position' => 3],
            ['column_id' => 'snoozed', 'column_name' => 'Snoozed', 'position' => 4],
        ];

        foreach ($defaultColumns as $column) {
            KanbanColumnConfig::create([
                'user_id' => $userId,
                'column_id' => $column['column_id'],
                'column_name' => $column['column_name'],
                'position' => $column['position'],
            ]);
        }
    }

    /**
     * Generate a unique column ID from column name.
     *
     * @param  string  $columnName  Column name
     * @return string Unique column ID
     */
    private function generateColumnId(string $columnName): string
    {
        // Convert to lowercase, replace spaces with underscores, remove special chars
        $columnId = mb_strtolower($columnName);
        $columnId = preg_replace('/[^a-z0-9_]/', '', str_replace(' ', '_', $columnId));
        $columnId = preg_replace('/_+/', '_', $columnId); // Remove multiple underscores
        $columnId = trim($columnId, '_');

        // Ensure uniqueness by appending number if needed
        $baseId = $columnId;
        $counter = 1;
        while (KanbanColumnConfig::where('column_id', $columnId)->exists()) {
            $columnId = $baseId.'_'.$counter;
            $counter++;
        }

        return $columnId;
    }
}
