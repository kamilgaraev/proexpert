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
        Schema::table('contracts', function (Blueprint $table) {
            // Изменяем значение по умолчанию для warranty_retention_percentage с 0 на 2.5
            $table->decimal('warranty_retention_percentage', 5, 3)->default(2.5)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            // Возвращаем значение по умолчанию обратно на 0
            $table->decimal('warranty_retention_percentage', 5, 3)->default(0)->change();
        });
    }
};
