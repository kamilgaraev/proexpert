<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('design_ifc_upload_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('package_id')->constrained('design_packages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('file_identity', 128);
            $table->text('s3_upload_id');
            $table->text('source_path');
            $table->string('original_name', 255);
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('part_size_bytes');
            $table->unsignedInteger('parts_count');
            $table->jsonb('uploaded_parts')->default('{}');
            $table->jsonb('completion')->nullable();
            $table->jsonb('payload')->default('{}');
            $table->string('status', 24)->default('active');
            $table->foreignId('completed_version_id')->nullable()->constrained('design_artifact_versions')->nullOnDelete();
            $table->timestampTz('expires_at');
            $table->timestampTz('cleaned_at')->nullable();
            $table->timestampsTz();
            $table->index(['organization_id', 'user_id', 'file_identity', 'status'], 'design_ifc_upload_sessions_resume_idx');
            $table->index(['status', 'expires_at'], 'design_ifc_upload_sessions_expiry_idx');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX design_ifc_upload_sessions_parts_gin_idx ON design_ifc_upload_sessions USING GIN (uploaded_parts)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('design_ifc_upload_sessions');
    }
};
