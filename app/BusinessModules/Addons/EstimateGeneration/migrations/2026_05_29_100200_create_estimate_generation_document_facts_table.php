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
        Schema::create('estimate_generation_document_facts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')->constrained('estimate_generation_documents')->cascadeOnDelete();
            $table->foreignId('page_id')->nullable()->constrained('estimate_generation_document_pages')->nullOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('session_id')->constrained('estimate_generation_sessions')->cascadeOnDelete();
            $table->string('fact_type', 100);
            $table->string('scope_key')->nullable();
            $table->text('label');
            $table->text('value_text')->nullable();
            $table->decimal('value_number', 18, 4)->nullable();
            $table->string('unit', 50)->nullable();
            $table->decimal('confidence', 5, 2);
            $table->jsonb('source_ref');
            $table->jsonb('normalized_payload')->nullable();
            $table->timestamps();

            $table->index(['session_id', 'fact_type'], 'estimate_generation_document_facts_session_type_idx');
            $table->index(['document_id', 'fact_type'], 'estimate_generation_document_facts_document_type_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE estimate_generation_document_facts
                    ADD CONSTRAINT estimate_generation_document_facts_fact_type_check
                    CHECK (fact_type IN ('object_type', 'total_area', 'room_area', 'zone_area', 'dimension', 'floor_count', 'height', 'volume', 'engineering_system', 'material', 'work_scope', 'estimate_row', 'table_row', 'note'))"
            );
            DB::statement(
                "ALTER TABLE estimate_generation_document_facts
                    ADD CONSTRAINT estimate_generation_document_facts_confidence_check
                    CHECK (confidence BETWEEN 0 AND 1)"
            );
            DB::statement(
                'CREATE INDEX estimate_generation_document_facts_source_ref_gin_idx
                    ON estimate_generation_document_facts USING GIN (source_ref)'
            );
            DB::statement(
                'CREATE INDEX estimate_generation_document_facts_normalized_payload_gin_idx
                    ON estimate_generation_document_facts USING GIN (normalized_payload)'
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS estimate_generation_document_facts_normalized_payload_gin_idx');
            DB::statement('DROP INDEX IF EXISTS estimate_generation_document_facts_source_ref_gin_idx');
            DB::statement('ALTER TABLE estimate_generation_document_facts DROP CONSTRAINT IF EXISTS estimate_generation_document_facts_confidence_check');
            DB::statement('ALTER TABLE estimate_generation_document_facts DROP CONSTRAINT IF EXISTS estimate_generation_document_facts_fact_type_check');
        }

        Schema::dropIfExists('estimate_generation_document_facts');
    }
};
