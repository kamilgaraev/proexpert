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
        Schema::create('design_normative_sources', function (Blueprint $table): void {
            $table->id();
            $table->text('code')->unique();
            $table->text('title');
            $table->text('version')->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->text('source_url')->nullable();
            $table->text('status')->default('active');
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();

            $table->index(['status', 'effective_from']);
        });

        Schema::create('design_document_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('normative_source_id')->nullable()->constrained('design_normative_sources')->nullOnDelete();
            $table->text('profile_code');
            $table->text('project_stage');
            $table->text('object_type')->nullable();
            $table->text('section_code');
            $table->text('section_title');
            $table->text('document_code');
            $table->text('document_title');
            $table->text('artifact_type');
            $table->boolean('required')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->jsonb('allowed_formats')->default('[]');
            $table->boolean('sheet_registry_required')->default(false);
            $table->text('normative_reference')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();

            $table->unique([
                'profile_code',
                'project_stage',
                'object_type',
                'section_code',
                'document_code',
            ], 'design_templates_profile_stage_object_section_doc_unique');
            $table->index(['profile_code', 'project_stage', 'object_type'], 'design_templates_profile_stage_object_idx');
            $table->index(['section_code', 'document_code']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX design_normative_sources_metadata_gin_idx ON design_normative_sources USING GIN (metadata)');
            DB::statement('CREATE INDEX design_document_templates_formats_gin_idx ON design_document_templates USING GIN (allowed_formats)');
            DB::statement('CREATE INDEX design_document_templates_metadata_gin_idx ON design_document_templates USING GIN (metadata)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('design_document_templates');
        Schema::dropIfExists('design_normative_sources');
    }
};
