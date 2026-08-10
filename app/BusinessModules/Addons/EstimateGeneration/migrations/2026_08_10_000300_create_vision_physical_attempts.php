<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estimate_generation_vision_physical_attempts', function (Blueprint $table): void {
            $table->uuid('attempt_id')->primary();
            $table->char('request_fingerprint', 64);
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('session_id');
            $table->unsignedBigInteger('document_id')->nullable();
            $table->unsignedBigInteger('page_id')->nullable();
            $table->unsignedBigInteger('unit_id')->nullable();
            $table->string('state', 32);
            $table->jsonb('response_payload')->nullable();
            $table->string('status', 32)->nullable();
            $table->unsignedSmallInteger('http_code')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('reported_model', 160)->nullable();
            $table->jsonb('price_snapshot')->nullable();
            $table->boolean('usage_recorded')->default(false);
            $table->timestampsTz();
            $table->index(['organization_id', 'session_id']);
        });
    }

    public function down(): void {}
};
