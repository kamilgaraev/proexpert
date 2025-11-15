<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Таблица категорий позиций сметы
        Schema::create('estimate_position_catalog_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->foreignId('parent_id')->nullable()->constrained('estimate_position_catalog_categories')->onDelete('cascade');
            $table->string('name');
            $table->text('description')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            $table->index('organization_id');
            $table->index('parent_id');
            $table->index(['organization_id', 'is_active']);
            $table->index('sort_order');
        });

        // Таблица справочника позиций сметы
        Schema::create('estimate_position_catalog', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->foreignId('category_id')->nullable()->constrained('estimate_position_catalog_categories')->onDelete('set null');
            $table->string('name');
            $table->string('code');
            $table->text('description')->nullable();
            $table->enum('item_type', ['work', 'material', 'equipment', 'labor']);
            $table->foreignId('measurement_unit_id')->constrained('measurement_units')->onDelete('restrict');
            $table->foreignId('work_type_id')->nullable()->constrained('work_types')->onDelete('set null');
            $table->decimal('unit_price', 15, 2);
            $table->decimal('direct_costs', 15, 2)->nullable();
            $table->decimal('overhead_percent', 10, 4)->nullable();
            $table->decimal('profit_percent', 10, 4)->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('usage_count')->default(0);
            $table->jsonb('metadata')->nullable();
            $table->foreignId('created_by_user_id')->constrained('users')->onDelete('cascade');
            $table->timestamps();
            $table->softDeletes();
            
            $table->index('organization_id');
            $table->index('category_id');
            $table->index('is_active');
            $table->index('item_type');
            $table->index('measurement_unit_id');
            $table->index('work_type_id');
            $table->index('created_by_user_id');
            $table->index(['organization_id', 'is_active']);
            $table->index(['organization_id', 'item_type']);
            $table->index(['organization_id', 'category_id']);
            
            // Уникальность кода в рамках организации
            $table->unique(['organization_id', 'code']);
        });

        // Таблица истории изменения цен
        Schema::create('estimate_position_price_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalog_item_id')->constrained('estimate_position_catalog')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->decimal('old_price', 15, 2);
            $table->decimal('new_price', 15, 2);
            $table->string('change_reason')->nullable();
            $table->timestamp('changed_at');
            $table->jsonb('metadata')->nullable();
            
            $table->index('catalog_item_id');
            $table->index('user_id');
            $table->index('changed_at');
            $table->index(['catalog_item_id', 'changed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('estimate_position_price_history');
        Schema::dropIfExists('estimate_position_catalog');
        Schema::dropIfExists('estimate_position_catalog_categories');
    }
};

