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
        Schema::create('estimate_generation_drawing_elements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('session_id')->constrained('estimate_generation_sessions')->cascadeOnDelete();
            $table->foreignId('document_id')->constrained('estimate_generation_documents')->cascadeOnDelete();
            $table->foreignId('page_id')->nullable()->constrained('estimate_generation_document_pages')->nullOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('type', 80);
            $table->text('label')->nullable();
            $table->text('value_text')->nullable();
            $table->decimal('value_number', 18, 4)->nullable();
            $table->string('unit', 50)->nullable();
            $table->jsonb('bbox')->nullable();
            $table->jsonb('geometry')->nullable();
            $table->decimal('confidence', 5, 4);
            $table->jsonb('source_ref');
            $table->jsonb('normalized_payload')->nullable();
            $table->timestamps();

            $table->index(['session_id', 'type'], 'estimate_generation_drawing_elements_session_type_idx');
            $table->index(['document_id', 'page_id'], 'estimate_generation_drawing_elements_document_page_idx');
        });

        Schema::create('estimate_generation_quantity_takeoffs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('session_id')->constrained('estimate_generation_sessions')->cascadeOnDelete();
            $table->foreignId('document_id')->constrained('estimate_generation_documents')->cascadeOnDelete();
            $table->foreignId('page_id')->nullable()->constrained('estimate_generation_document_pages')->nullOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->jsonb('source_element_ids')->nullable();
            $table->string('scope_key')->nullable();
            $table->jsonb('work_intent');
            $table->text('name');
            $table->string('unit', 50);
            $table->decimal('quantity', 18, 4);
            $table->text('formula')->nullable();
            $table->decimal('confidence', 5, 4);
            $table->jsonb('source_refs');
            $table->jsonb('normalized_payload')->nullable();
            $table->timestamps();

            $table->index(['session_id', 'scope_key'], 'estimate_generation_takeoffs_session_scope_idx');
            $table->index(['document_id', 'page_id'], 'estimate_generation_takeoffs_document_page_idx');
        });

        Schema::create('estimate_generation_scope_inferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('session_id')->constrained('estimate_generation_sessions')->cascadeOnDelete();
            $table->foreignId('document_id')->nullable()->constrained('estimate_generation_documents')->nullOnDelete();
            $table->foreignId('page_id')->nullable()->constrained('estimate_generation_document_pages')->nullOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('inference_type', 80);
            $table->text('title');
            $table->text('description')->nullable();
            $table->jsonb('source_refs');
            $table->jsonb('normative_basis')->nullable();
            $table->jsonb('work_intent');
            $table->decimal('confidence', 5, 4);
            $table->boolean('review_required')->default(true);
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->index(['session_id', 'inference_type'], 'estimate_generation_scope_inferences_session_type_idx');
            $table->index(['review_required', 'confidence'], 'estimate_generation_scope_inferences_review_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE estimate_generation_drawing_elements ADD CONSTRAINT estimate_generation_drawing_elements_confidence_check CHECK (confidence BETWEEN 0 AND 1)");
            DB::statement("ALTER TABLE estimate_generation_quantity_takeoffs ADD CONSTRAINT estimate_generation_takeoffs_confidence_check CHECK (confidence BETWEEN 0 AND 1)");
            DB::statement("ALTER TABLE estimate_generation_scope_inferences ADD CONSTRAINT estimate_generation_scope_inferences_confidence_check CHECK (confidence BETWEEN 0 AND 1)");
            DB::statement("CREATE INDEX estimate_generation_drawing_elements_source_ref_gin_idx ON estimate_generation_drawing_elements USING GIN (source_ref)");
            DB::statement("CREATE INDEX estimate_generation_drawing_elements_payload_gin_idx ON estimate_generation_drawing_elements USING GIN (normalized_payload)");
            DB::statement("CREATE INDEX estimate_generation_takeoffs_work_intent_gin_idx ON estimate_generation_quantity_takeoffs USING GIN (work_intent)");
            DB::statement("CREATE INDEX estimate_generation_takeoffs_source_refs_gin_idx ON estimate_generation_quantity_takeoffs USING GIN (source_refs)");
            DB::statement("CREATE INDEX estimate_generation_scope_inferences_work_intent_gin_idx ON estimate_generation_scope_inferences USING GIN (work_intent)");
            DB::statement("CREATE INDEX estimate_generation_scope_inferences_source_refs_gin_idx ON estimate_generation_scope_inferences USING GIN (source_refs)");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS estimate_generation_scope_inferences_source_refs_gin_idx');
            DB::statement('DROP INDEX IF EXISTS estimate_generation_scope_inferences_work_intent_gin_idx');
            DB::statement('DROP INDEX IF EXISTS estimate_generation_takeoffs_source_refs_gin_idx');
            DB::statement('DROP INDEX IF EXISTS estimate_generation_takeoffs_work_intent_gin_idx');
            DB::statement('DROP INDEX IF EXISTS estimate_generation_drawing_elements_payload_gin_idx');
            DB::statement('DROP INDEX IF EXISTS estimate_generation_drawing_elements_source_ref_gin_idx');
            DB::statement('ALTER TABLE estimate_generation_scope_inferences DROP CONSTRAINT IF EXISTS estimate_generation_scope_inferences_confidence_check');
            DB::statement('ALTER TABLE estimate_generation_quantity_takeoffs DROP CONSTRAINT IF EXISTS estimate_generation_takeoffs_confidence_check');
            DB::statement('ALTER TABLE estimate_generation_drawing_elements DROP CONSTRAINT IF EXISTS estimate_generation_drawing_elements_confidence_check');
        }

        Schema::dropIfExists('estimate_generation_scope_inferences');
        Schema::dropIfExists('estimate_generation_quantity_takeoffs');
        Schema::dropIfExists('estimate_generation_drawing_elements');
    }
};
