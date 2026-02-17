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
        Schema::table('estimates', function (Blueprint $table) {
            $table->decimal('total_base_direct_costs', 15, 2)->nullable()->after('total_direct_costs')->comment('Прямые затраты в базисных ценах');
            $table->decimal('total_base_materials_cost', 15, 2)->nullable()->after('total_base_direct_costs')->comment('Стоимость материалов в базисных ценах');
            $table->decimal('total_base_machinery_cost', 15, 2)->nullable()->after('total_base_materials_cost')->comment('Стоимость механизмов в базисных ценах');
            $table->decimal('total_base_labor_cost', 15, 2)->nullable()->after('total_base_machinery_cost')->comment('ФОТ в базисных ценах');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('estimates', function (Blueprint $table) {
            //
        });
    }
};
