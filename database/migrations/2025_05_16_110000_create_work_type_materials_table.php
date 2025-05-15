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
        Schema::create('work_type_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->foreignId('work_type_id')->constrained('work_types')->onDelete('cascade');
            $table->foreignId('material_id')->constrained('materials')->onDelete('cascade');
            
            $table->decimal('default_quantity', 12, 4)->comment('Количество материала на единицу измерения вида работ');
            $table->text('notes')->nullable()->comment('Примечания к норме');
            
            $table->timestamps();
            $table->softDeletes(); // Если нужно мягкое удаление для истории норм

            $table->unique(['organization_id', 'work_type_id', 'material_id'], 'idx_org_work_type_material_unique');
            $table->index('work_type_id');
            $table->index('material_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_type_materials');
    }
}; 