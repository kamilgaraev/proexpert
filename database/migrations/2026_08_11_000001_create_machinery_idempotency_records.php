<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('machinery_idempotency_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('idempotency_key', 100);
            $table->string('operation_type', 80);
            $table->char('request_hash', 64);
            $table->string('response_type')->nullable();
            $table->unsignedBigInteger('response_id')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'actor_user_id', 'idempotency_key'], 'machinery_idempotency_actor_key_unique');
            $table->index(['response_type', 'response_id'], 'machinery_idempotency_response_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('machinery_idempotency_records');
    }
};
