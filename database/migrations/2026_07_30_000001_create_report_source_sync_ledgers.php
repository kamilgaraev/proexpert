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
            $table->string('status', 24)->default('pending');
            $table->unsignedBigInteger('source_count')->default(0);
            $table->unsignedBigInteger('projected_count')->default(0);
            $table->unsignedBigInteger('gap_count')->default(0);
            $table->unsignedBigInteger('unknown_count')->default(0);
            $table->timestampTz('source_watermark')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'source_code']);
        });
        DB::statement("ALTER TABLE report_source_sync_ledgers ADD CONSTRAINT report_source_sync_status_check CHECK (status IN ('pending','running','ready','partial','failed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('report_source_sync_ledgers');
    }
};
