<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('report_source_sync_ledgers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('source_code', 96);
            $table->jsonb('cursor')->default('{}');
            $table->jsonb('target_cursor')->default('{}');
            $table->string('owner_checksum', 64);
            $table->string('completed_owner_checksum', 64)->nullable();
            $table->string('status', 24)->default('pending');
            $table->unsignedBigInteger('source_count')->default(0);
            $table->unsignedBigInteger('projected_count')->default(0);
            $table->unsignedBigInteger('gap_count')->default(0);
            $table->unsignedBigInteger('unknown_count')->default(0);
            $table->jsonb('unknown_owner_keys')->default('[]');
            $table->timestampTz('source_watermark')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'source_code']);
        });
        DB::statement("ALTER TABLE report_source_sync_ledgers ADD CONSTRAINT report_source_sync_status_check CHECK (status IN ('pending','running','ready','partial','failed'))");
        DB::statement("ALTER TABLE report_source_sync_ledgers ADD CONSTRAINT report_source_sync_owner_hash_check CHECK (owner_checksum ~ '^[a-f0-9]{64}$' AND (completed_owner_checksum IS NULL OR completed_owner_checksum ~ '^[a-f0-9]{64}$'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('report_source_sync_ledgers');
    }
};
