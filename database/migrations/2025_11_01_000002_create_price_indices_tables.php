<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_indices', function (Blueprint $table) {
            $table->id();
            $table->enum('index_type', [
                'construction_general',
                'construction_special',
                'equipment',
                'design_work',
                'survey_work',
                'other'
            ])->default('construction_general');
            $table->string('region_code', 50)->nullable();
            $table->string('region_name')->nullable();
            $table->integer('year');
            $table->integer('quarter')->nullable();
            $table->integer('month')->nullable();
            $table->decimal('index_value', 10, 4);
            $table->string('source')->nullable();
            $table->date('publication_date')->nullable();
            $table->text('notes')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
            
            $table->index(['index_type', 'region_code', 'year', 'quarter']);
            $table->index(['region_code', 'year']);
            $table->unique(['index_type', 'region_code', 'year', 'quarter', 'month']);
        });

        Schema::create('regional_coefficients', function (Blueprint $table) {
            $table->id();
            $table->enum('coefficient_type', [
                'climatic',
                'seismic',
                'altitude',
                'winter',
                'difficult_conditions',
                'regional',
                'other'
            ]);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('region_code', 50)->nullable();
            $table->string('region_name')->nullable();
            $table->decimal('coefficient_value', 10, 4);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->string('applies_to')->nullable();
            $table->text('application_rules')->nullable();
            $table->boolean('is_active')->default(true);
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
            
            $table->index(['coefficient_type', 'region_code']);
            $table->index(['is_active', 'effective_from', 'effective_to']);
        });

        Schema::create('rate_coefficient_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coefficient_id')->constrained('regional_coefficients')->onDelete('cascade');
            $table->foreignId('rate_id')->nullable()->constrained('normative_rates')->onDelete('cascade');
            $table->foreignId('section_id')->nullable()->constrained('normative_sections')->onDelete('cascade');
            $table->foreignId('collection_id')->nullable()->constrained('normative_collections')->onDelete('cascade');
            $table->enum('application_level', ['rate', 'section', 'collection']);
            $table->text('conditions')->nullable();
            $table->boolean('is_mandatory')->default(false);
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
            
            $table->index(['coefficient_id', 'application_level']);
            $table->index('rate_id');
            $table->index('section_id');
            $table->index('collection_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rate_coefficient_applications');
        Schema::dropIfExists('regional_coefficients');
        Schema::dropIfExists('price_indices');
    }
};
