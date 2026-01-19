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
        Schema::create('kanban_columns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('column_id', 100)->index(); // Unique identifier for the column
            $table->string('column_name', 255); // Display name
            $table->string('gmail_label_id', 255)->nullable(); // Gmail label ID if mapped
            $table->integer('position')->default(0); // Order/position of column
            $table->string('color', 50)->nullable(); // Optional color/theme
            $table->timestamps();

            // Indexes for efficient querying
            $table->index(['user_id', 'position']);
            $table->unique(['user_id', 'column_id']); // One column_id per user
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kanban_columns');
    }
};
