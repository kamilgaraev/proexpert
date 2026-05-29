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
        Schema::table('estimate_generation_documents', function (Blueprint $table): void {
            $table->string('status', 50)->default('uploaded')->after('storage_path');
            $table->string('processing_stage', 100)->default('stored')->after('status');
            $table->unsignedTinyInteger('progress_percent')->default(0)->after('processing_stage');
            $table->unsignedBigInteger('file_size_bytes')->nullable()->after('progress_percent');
            $table->string('checksum_sha256', 64)->nullable()->after('file_size_bytes');
            $table->unsignedInteger('page_count')->nullable()->after('checksum_sha256');
            $table->unsignedInteger('processed_page_count')->default(0)->after('page_count');
            $table->string('ocr_provider', 100)->nullable()->after('processed_page_count');
            $table->string('ocr_model', 100)->nullable()->after('ocr_provider');
            $table->unsignedSmallInteger('ocr_attempts')->default(0)->after('ocr_model');
            $table->decimal('quality_score', 5, 2)->nullable()->after('ocr_attempts');
            $table->string('quality_level', 50)->nullable()->after('quality_score');
            $table->jsonb('quality_flags')->nullable()->after('quality_level');
            $table->jsonb('facts_summary')->nullable()->after('quality_flags');
            $table->string('error_code', 100)->nullable()->after('facts_summary');
            $table->string('error_message_key', 150)->nullable()->after('error_code');
            $table->jsonb('error_context')->nullable()->after('error_message_key');
            $table->timestamp('ocr_started_at')->nullable()->after('error_context');
            $table->timestamp('ocr_finished_at')->nullable()->after('ocr_started_at');
            $table->timestamp('ignored_at')->nullable()->after('ocr_finished_at');

            $table->index(['session_id', 'status'], 'estimate_generation_documents_session_status_idx');
            $table->index(['organization_id', 'checksum_sha256'], 'estimate_generation_documents_org_checksum_idx');
            $table->index(['project_id', 'created_at'], 'estimate_generation_documents_project_created_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE estimate_generation_documents
                    ADD CONSTRAINT estimate_generation_documents_status_check
                    CHECK (status IN ('uploaded', 'queued', 'processing', 'ready', 'needs_review', 'failed', 'ignored'))"
            );
            DB::statement(
                "ALTER TABLE estimate_generation_documents
                    ADD CONSTRAINT estimate_generation_documents_processing_stage_check
                    CHECK (processing_stage IN ('stored', 'preflight', 'pdf_text_layer', 'ocr_request', 'ocr_polling', 'normalization', 'fact_extraction', 'quality_check', 'completed'))"
            );
            DB::statement(
                "ALTER TABLE estimate_generation_documents
                    ADD CONSTRAINT estimate_generation_documents_progress_percent_check
                    CHECK (progress_percent BETWEEN 0 AND 100)"
            );
            DB::statement(
                "ALTER TABLE estimate_generation_documents
                    ADD CONSTRAINT estimate_generation_documents_quality_level_check
                    CHECK (quality_level IS NULL OR quality_level IN ('good', 'acceptable', 'low', 'unusable'))"
            );
            DB::statement(
                "ALTER TABLE estimate_generation_documents
                    ADD CONSTRAINT estimate_generation_documents_quality_score_check
                    CHECK (quality_score IS NULL OR quality_score BETWEEN 0 AND 1)"
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE estimate_generation_documents DROP CONSTRAINT IF EXISTS estimate_generation_documents_quality_score_check');
            DB::statement('ALTER TABLE estimate_generation_documents DROP CONSTRAINT IF EXISTS estimate_generation_documents_quality_level_check');
            DB::statement('ALTER TABLE estimate_generation_documents DROP CONSTRAINT IF EXISTS estimate_generation_documents_progress_percent_check');
            DB::statement('ALTER TABLE estimate_generation_documents DROP CONSTRAINT IF EXISTS estimate_generation_documents_processing_stage_check');
            DB::statement('ALTER TABLE estimate_generation_documents DROP CONSTRAINT IF EXISTS estimate_generation_documents_status_check');
        }

        Schema::table('estimate_generation_documents', function (Blueprint $table): void {
            $table->dropIndex('estimate_generation_documents_project_created_idx');
            $table->dropIndex('estimate_generation_documents_org_checksum_idx');
            $table->dropIndex('estimate_generation_documents_session_status_idx');
            $table->dropColumn([
                'ignored_at',
                'ocr_finished_at',
                'ocr_started_at',
                'error_context',
                'error_message_key',
                'error_code',
                'facts_summary',
                'quality_flags',
                'quality_level',
                'quality_score',
                'ocr_attempts',
                'ocr_model',
                'ocr_provider',
                'processed_page_count',
                'page_count',
                'checksum_sha256',
                'file_size_bytes',
                'progress_percent',
                'processing_stage',
                'status',
            ]);
        });
    }
};
