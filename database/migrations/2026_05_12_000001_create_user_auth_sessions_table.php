<?php

declare(strict_types=1);

use App\Enums\AuthSessionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_auth_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('session_uuid')->unique();
            $table->string('device_fingerprint', 64)->index();
            $table->string('device_name')->nullable();
            $table->text('user_agent')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('ip_country', 2)->nullable();
            $table->string('ip_city')->nullable();
            $table->unsignedSmallInteger('risk_score')->default(0);
            $table->jsonb('risk_flags')->default('[]');
            $table->string('status')->default(AuthSessionStatus::Active->value)->index();
            $table->boolean('is_trusted')->default(false);
            $table->timestampTz('first_seen_at')->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->string('revoked_reason')->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'device_fingerprint']);
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_auth_sessions');
    }
};
