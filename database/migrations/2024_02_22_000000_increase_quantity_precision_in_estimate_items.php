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
        Schema::table('estimate_items', function (Blueprint $table) {
            // Расширяем до 8 знаков после запятой, чтобы ловить доли копеек из Гранд-Сметы
            $table->decimal('quantity', 20, 8)->change();
            // Также на всякий случай расширим цену, иногда там тоже много знаков
            $table->decimal('unit_price', 20, 4)->change();
            $table->decimal('current_unit_price', 20, 4)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('estimate_items', function (Blueprint $table) {
            $table->decimal('quantity', 18, 4)->change();
            $table->decimal('unit_price', 15, 2)->change();
            $table->decimal('current_unit_price', 15, 2)->change();
        });
    }
};
