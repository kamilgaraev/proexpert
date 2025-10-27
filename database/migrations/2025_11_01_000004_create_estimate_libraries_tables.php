<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estimate_libraries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('created_by_user_id')->constrained('users')->onDelete('cascade');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category', 100)->nullable();
            $table->enum('access_level', ['private', 'organization', 'public'])->default('private');
            $table->jsonb('tags')->nullable();
            $table->integer('usage_count')->default(0);
            $table->boolean('is_active')->default(true);
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['organization_id', 'is_active']);
            $table->index(['access_level', 'is_active']);
            $table->index('category');
        });

        DB::statement('CREATE INDEX estimate_libraries_tags_gin_idx ON estimate_libraries USING GIN(tags)');
        
        DB::statement('ALTER TABLE estimate_libraries ADD COLUMN search_vector tsvector');
        DB::statement('CREATE INDEX estimate_libraries_search_idx ON estimate_libraries USING GIN(search_vector)');
        
        DB::statement("
            CREATE OR REPLACE FUNCTION estimate_libraries_search_trigger() RETURNS trigger AS $$
            BEGIN
                NEW.search_vector :=
                    setweight(to_tsvector('russian', coalesce(NEW.name, '')), 'A') ||
                    setweight(to_tsvector('russian', coalesce(NEW.description, '')), 'B') ||
                    setweight(to_tsvector('russian', coalesce(NEW.category, '')), 'C');
                RETURN NEW;
            END
            $$ LANGUAGE plpgsql;
        ");

        DB::statement("
            CREATE TRIGGER estimate_libraries_search_update
            BEFORE INSERT OR UPDATE ON estimate_libraries
            FOR EACH ROW
            EXECUTE FUNCTION estimate_libraries_search_trigger();
        ");

        Schema::create('estimate_library_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->constrained('estimate_libraries')->onDelete('cascade');
            $table->string('name');
            $table->text('description')->nullable();
            $table->jsonb('parameters')->nullable();
            $table->text('calculation_rules')->nullable();
            $table->integer('positions_count')->default(0);
            $table->integer('usage_count')->default(0);
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            $table->index('library_id');
        });

        DB::statement('CREATE INDEX estimate_library_items_parameters_gin_idx ON estimate_library_items USING GIN(parameters)');

        Schema::create('estimate_library_item_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_item_id')->constrained('estimate_library_items')->onDelete('cascade');
            $table->foreignId('normative_rate_id')->nullable()->constrained('normative_rates')->onDelete('set null');
            $table->string('normative_rate_code', 100)->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('measurement_unit', 50)->nullable();
            $table->integer('sort_order')->default(0);
            $table->string('quantity_formula')->nullable();
            $table->decimal('default_quantity', 15, 4)->default(0);
            $table->decimal('coefficient', 10, 4)->default(1);
            $table->jsonb('parameters_mapping')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
            
            $table->index(['library_item_id', 'sort_order']);
            $table->index('normative_rate_id');
        });

        Schema::create('estimate_library_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_item_id')->constrained('estimate_library_items')->onDelete('cascade');
            $table->foreignId('estimate_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->jsonb('applied_parameters')->nullable();
            $table->integer('positions_added')->default(0);
            $table->timestamp('used_at');
            $table->jsonb('metadata')->nullable();
            
            $table->index(['library_item_id', 'used_at']);
            $table->index('estimate_id');
            $table->index(['user_id', 'used_at']);
        });

        DB::statement('CREATE INDEX estimate_library_usage_applied_parameters_gin_idx ON estimate_library_usage USING GIN(applied_parameters)');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS estimate_libraries_search_update ON estimate_libraries');
        DB::statement('DROP FUNCTION IF EXISTS estimate_libraries_search_trigger()');
        
        Schema::dropIfExists('estimate_library_usage');
        Schema::dropIfExists('estimate_library_item_positions');
        Schema::dropIfExists('estimate_library_items');
        Schema::dropIfExists('estimate_libraries');
    }
};

