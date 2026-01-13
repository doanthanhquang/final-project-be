<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('email_workflow_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('email_id', 255)->index(); // Gmail message ID
            $table->string('column_id', 50)->default('inbox'); // inbox, todo, in_progress, done, snoozed
            $table->integer('position')->default(0); // Position within column
            $table->timestamp('snoozed_until')->nullable();
            $table->string('previous_column_id', 50)->nullable(); // For restoring after snooze
            $table->timestamps();

            // Indexes for efficient querying
            $table->index(['user_id', 'column_id']);
            $table->index(['user_id', 'email_id']);
            $table->index('snoozed_until');

            // Unique constraint: one workflow state per user per email
            $table->unique(['user_id', 'email_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_workflow_states');
    }
};
