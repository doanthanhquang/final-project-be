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
        Schema::create('email_embeddings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('email_id', 255)->index(); // Gmail message ID
            $table->json('embedding_vector'); // Store as JSON array (fallback if pgvector not available)
            $table->string('model_used', 100); // e.g., text-embedding-3-small, text-embedding-004
            $table->integer('dimensions')->default(1536); // Vector dimensions
            $table->timestamps();

            // Indexes
            $table->index(['user_id', 'email_id']);

            // Unique constraint: one embedding per user per email
            $table->unique(['user_id', 'email_id']);

            // Note: If pgvector extension is available, consider adding:
            // $table->vector('embedding_vector', 1536); // For PostgreSQL pgvector
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_embeddings');
    }
};
