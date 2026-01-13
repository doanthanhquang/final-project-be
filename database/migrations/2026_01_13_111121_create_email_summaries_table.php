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
        Schema::create('email_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('email_id', 255)->index(); // Gmail message ID
            $table->text('summary_text'); // The AI-generated summary
            $table->string('model_used', 100); // e.g., gpt-4o-mini, gemini-1.5-flash
            $table->integer('tokens_used')->nullable(); // For cost tracking
            $table->timestamps();

            // Indexes
            $table->index(['user_id', 'email_id']);

            // Unique constraint: one summary per user per email
            $table->unique(['user_id', 'email_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_summaries');
    }
};
