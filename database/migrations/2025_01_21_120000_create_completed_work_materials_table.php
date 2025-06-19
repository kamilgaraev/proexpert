<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('completed_work_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('completed_work_id')->constrained('completed_works')->onDelete('cascade');
            $table->foreignId('material_id')->constrained('materials')->onDelete('cascade');
            $table->decimal('quantity', 15, 4)->comment('Фактическое количество использованного материала');
            $table->decimal('unit_price', 15, 2)->nullable()->comment('Цена за единицу на момент списания');
            $table->decimal('total_amount', 15, 2)->nullable()->comment('Общая стоимость материала');
            $table->text('notes')->nullable()->comment('Примечания к использованию материала');
            $table->timestamps();

            $table->unique(['completed_work_id', 'material_id'], 'idx_completed_work_material_unique');
            $table->index('completed_work_id');
            $table->index('material_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('completed_work_materials');
    }
}; 