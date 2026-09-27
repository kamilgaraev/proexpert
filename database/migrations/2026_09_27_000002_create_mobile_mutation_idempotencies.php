<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_mutation_idempotencies', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('user_id');
            $table->string('idempotency_key', 128);
            $table->string('operation', 128);
            $table->char('payload_fingerprint', 64);
            $table->unsignedBigInteger('resource_id');
            $table->timestamps();
            $table->unique(['organization_id', 'user_id', 'idempotency_key'], 'mobile_mutation_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_mutation_idempotencies');
    }
};
