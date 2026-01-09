<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auth_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('access_token', 128)->unique();
            $table->timestamp('access_expires_at');
            $table->string('refresh_token', 128)->unique();
            $table->timestamp('refresh_expires_at');
            $table->boolean('revoked')->default(false);
            $table->timestamps();
            $table->index(['user_id', 'revoked']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_tokens');
    }
};
