<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auth_registration_attempts', function (Blueprint $table): void {
            $table->id();
            $table->string('audience', 32);
            $table->string('idempotency_key', 128);
            $table->char('request_hash', 64);
            $table->string('status', 16);
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->jsonb('response')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampsTz();

            $table->unique(['audience', 'idempotency_key'], 'auth_registration_attempts_audience_key_unique');
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_registration_attempts');
    }
};
