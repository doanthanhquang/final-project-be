<?php

namespace Database\Seeders;

use App\Models\KanbanColumnConfig;
use App\Models\User;
use Illuminate\Database\Seeder;

class KanbanColumnSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create default columns for existing users
        $users = User::all();
        $defaultColumns = [
            ['column_id' => 'inbox', 'column_name' => 'Inbox', 'position' => 0],
            ['column_id' => 'todo', 'column_name' => 'To Do', 'position' => 1],
            ['column_id' => 'done', 'column_name' => 'Done', 'position' => 2],
            ['column_id' => 'snoozed', 'column_name' => 'Snoozed', 'position' => 3],
        ];

        foreach ($users as $user) {
            // Check if user already has columns
            $existingColumns = KanbanColumnConfig::forUser($user->id)->count();
            if ($existingColumns > 0) {
                continue; // Skip if user already has columns
            }

            // Create default columns
            foreach ($defaultColumns as $column) {
                KanbanColumnConfig::create([
                    'user_id' => $user->id,
                    'column_id' => $column['column_id'],
                    'column_name' => $column['column_name'],
                    'position' => $column['position'],
                ]);
            }
        }
    }
}
