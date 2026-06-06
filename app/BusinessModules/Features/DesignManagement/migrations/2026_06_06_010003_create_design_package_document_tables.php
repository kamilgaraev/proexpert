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
        Schema::create('design_package_sections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('package_id')->constrained('design_packages')->cascadeOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('design_document_templates')->nullOnDelete();
            $table->text('code');
            $table->text('title');
            $table->text('project_stage');
            $table->text('object_type')->nullable();
            $table->text('status')->default('not_started');
            $table->boolean('required')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->text('normative_reference')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();

            $table->unique(['package_id', 'code'], 'design_package_sections_package_code_unique');
            $table->index(['organization_id', 'project_id']);
            $table->index(['package_id', 'status']);
            $table->index(['project_id', 'project_stage']);
        });

        Schema::table('design_artifacts', function (Blueprint $table): void {
            $table->foreignId('section_id')->nullable()->after('package_id')->constrained('design_package_sections')->nullOnDelete();
            $table->text('document_code')->nullable()->after('artifact_type');
            $table->text('document_title')->nullable()->after('document_code');
            $table->boolean('requires_sheet_registry')->default(false)->after('document_title');

            $table->index(['section_id', 'document_code']);
            $table->index(['package_id', 'document_code']);
        });

        Schema::table('design_artifact_versions', function (Blueprint $table): void {
            $table->text('file_format')->default('ifc')->after('source_format');
            $table->text('revision_label')->nullable()->after('revision');
            $table->text('source_sha256')->nullable()->after('source_size_bytes');
            $table->unsignedInteger('page_count')->nullable()->after('source_sha256');
            $table->unsignedInteger('sheet_count')->nullable()->after('page_count');
            $table->jsonb('extracted_metadata')->default('{}')->after('sheet_count');

            $table->index(['organization_id', 'file_format']);
            $table->index(['artifact_id', 'revision_label']);
            $table->index(['source_sha256']);
        });

        Schema::create('design_document_sheets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('package_id')->constrained('design_packages')->cascadeOnDelete();
            $table->foreignId('section_id')->nullable()->constrained('design_package_sections')->nullOnDelete();
            $table->foreignId('artifact_id')->nullable()->constrained('design_artifacts')->cascadeOnDelete();
            $table->foreignId('version_id')->nullable()->constrained('design_artifact_versions')->cascadeOnDelete();
            $table->text('sheet_number');
            $table->text('sheet_code')->nullable();
            $table->text('sheet_title');
            $table->text('revision')->nullable();
            $table->unsignedInteger('file_page_number')->nullable();
            $table->unsignedInteger('total_sheets')->nullable();
            $table->text('status')->default('active');
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();

            $table->index(['organization_id', 'project_id']);
            $table->index(['package_id', 'section_id']);
            $table->index(['artifact_id', 'version_id']);
            $table->index(['version_id', 'sheet_number']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX design_package_sections_metadata_gin_idx ON design_package_sections USING GIN (metadata)');
            DB::statement('CREATE INDEX design_artifact_versions_extracted_metadata_gin_idx ON design_artifact_versions USING GIN (extracted_metadata)');
            DB::statement('CREATE INDEX design_document_sheets_metadata_gin_idx ON design_document_sheets USING GIN (metadata)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('design_document_sheets');

        Schema::table('design_artifact_versions', function (Blueprint $table): void {
            $table->dropIndex(['organization_id', 'file_format']);
            $table->dropIndex(['artifact_id', 'revision_label']);
            $table->dropIndex(['source_sha256']);
            $table->dropColumn([
                'file_format',
                'revision_label',
                'source_sha256',
                'page_count',
                'sheet_count',
                'extracted_metadata',
            ]);
        });

        Schema::table('design_artifacts', function (Blueprint $table): void {
            $table->dropIndex(['section_id', 'document_code']);
            $table->dropIndex(['package_id', 'document_code']);
            $table->dropConstrainedForeignId('section_id');
            $table->dropColumn([
                'document_code',
                'document_title',
                'requires_sheet_registry',
            ]);
        });

        Schema::dropIfExists('design_package_sections');
    }
};
