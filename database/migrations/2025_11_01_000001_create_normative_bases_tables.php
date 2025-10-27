<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('normative_base_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('version', 50)->nullable();
            $table->date('effective_date')->nullable();
            $table->date('last_updated_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
            
            $table->index('is_active');
        });

        Schema::create('normative_collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('base_type_id')->constrained('normative_base_types')->onDelete('cascade');
            $table->string('code', 100)->index();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('version', 50)->nullable();
            $table->date('effective_date')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
            
            $table->unique(['base_type_id', 'code']);
            $table->index(['base_type_id', 'is_active']);
        });

        Schema::create('normative_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collection_id')->constrained('normative_collections')->onDelete('cascade');
            $table->foreignId('parent_id')->nullable()->constrained('normative_sections')->onDelete('cascade');
            $table->string('code', 100)->index();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('path', 500)->nullable();
            $table->integer('level')->default(0);
            $table->integer('sort_order')->default(0);
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
            
            $table->index(['collection_id', 'parent_id']);
            $table->index('path');
        });

        Schema::create('normative_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collection_id')->constrained('normative_collections')->onDelete('cascade');
            $table->foreignId('section_id')->nullable()->constrained('normative_sections')->onDelete('set null');
            $table->string('code', 100)->index();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('measurement_unit', 50)->nullable();
            
            $table->decimal('base_price', 15, 2)->default(0);
            $table->decimal('materials_cost', 15, 2)->default(0);
            $table->decimal('machinery_cost', 15, 2)->default(0);
            $table->decimal('labor_cost', 15, 2)->default(0);
            
            $table->decimal('labor_hours', 15, 4)->default(0);
            $table->decimal('machinery_hours', 15, 4)->default(0);
            
            $table->string('base_price_year', 10)->nullable();
            $table->text('notes')->nullable();
            $table->jsonb('metadata')->nullable();
            
            $table->timestamps();
            
            $table->index(['collection_id', 'code']);
            $table->index('section_id');
        });

        DB::statement('ALTER TABLE normative_rates ADD COLUMN search_vector tsvector');
        
        DB::statement("
            CREATE INDEX normative_rates_search_idx ON normative_rates USING GIN(search_vector)
        ");
        
        DB::statement("
            CREATE INDEX normative_rates_name_trgm_idx ON normative_rates USING GIN(name gin_trgm_ops)
        ");
        
        DB::statement("
            CREATE INDEX normative_rates_code_trgm_idx ON normative_rates USING GIN(code gin_trgm_ops)
        ");

        DB::statement("
            CREATE OR REPLACE FUNCTION normative_rates_search_trigger() RETURNS trigger AS $$
            BEGIN
                NEW.search_vector :=
                    setweight(to_tsvector('russian', coalesce(NEW.code, '')), 'A') ||
                    setweight(to_tsvector('russian', coalesce(NEW.name, '')), 'B') ||
                    setweight(to_tsvector('russian', coalesce(NEW.description, '')), 'C');
                RETURN NEW;
            END
            $$ LANGUAGE plpgsql;
        ");

        DB::statement("
            CREATE TRIGGER normative_rates_search_update
            BEFORE INSERT OR UPDATE ON normative_rates
            FOR EACH ROW
            EXECUTE FUNCTION normative_rates_search_trigger();
        ");

        Schema::create('normative_rate_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rate_id')->constrained('normative_rates')->onDelete('cascade');
            $table->enum('resource_type', ['material', 'machinery', 'labor', 'equipment', 'other']);
            $table->string('code', 100)->nullable();
            $table->string('name');
            $table->string('measurement_unit', 50)->nullable();
            $table->decimal('consumption', 15, 4)->default(0);
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->decimal('total_cost', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
            
            $table->index(['rate_id', 'resource_type']);
        });
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS normative_rates_search_update ON normative_rates');
        DB::statement('DROP FUNCTION IF EXISTS normative_rates_search_trigger()');
        
        Schema::dropIfExists('normative_rate_resources');
        Schema::dropIfExists('normative_rates');
        Schema::dropIfExists('normative_sections');
        Schema::dropIfExists('normative_collections');
        Schema::dropIfExists('normative_base_types');
    }
};

