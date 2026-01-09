<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_providers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->enum('provider_type', ['gmail', 'imap'])->default('gmail');
            $table->text('refresh_token')->nullable(); // For Gmail OAuth2
            $table->text('access_token')->nullable(); // Temporary, not persisted long-term
            $table->timestamp('access_token_expires_at')->nullable();
            $table->text('encrypted_credentials')->nullable(); // For IMAP (encrypted username/password)
            $table->string('imap_host')->nullable(); // For IMAP
            $table->integer('imap_port')->nullable()->default(993);
            $table->string('smtp_host')->nullable(); // For SMTP
            $table->integer('smtp_port')->nullable()->default(587);
            $table->boolean('connected')->default(false);
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'provider_type', 'connected']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_providers');
    }
};
