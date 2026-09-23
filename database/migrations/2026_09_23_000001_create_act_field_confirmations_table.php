<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('act_field_confirmations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('act_id')->constrained('contract_performance_acts')->restrictOnDelete();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('file_id')->constrained('files')->restrictOnDelete();
            $table->string('idempotency_key', 128);
            $table->char('signature_sha256', 64);
            $table->timestampTz('confirmed_at');
            $table->timestampsTz();
            $table->unique(['organization_id', 'idempotency_key'], 'act_field_confirmations_org_key_unique');
            $table->unique(['act_id', 'user_id'], 'act_field_confirmations_act_user_unique');
            $table->index(['act_id', 'confirmed_at'], 'act_field_confirmations_act_confirmed_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('act_field_confirmations');
    }
};
