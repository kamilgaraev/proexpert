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
        // Увеличиваем длину полей названия, так как в сметах они бывают очень длинными
        Schema::table('materials', function (Blueprint $table) {
            $table->text('name')->change();
        });

        Schema::table('machinery', function (Blueprint $table) {
            $table->text('name')->change();
        });

        Schema::table('labor_resources', function (Blueprint $table) {
            $table->text('name')->change();
        });

        Schema::table('estimate_items', function (Blueprint $table) {
            $table->text('name')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->string('name', 255)->change();
        });

        Schema::table('machinery', function (Blueprint $table) {
            $table->string('name', 255)->change();
        });

        Schema::table('labor_resources', function (Blueprint $table) {
            $table->string('name', 255)->change();
        });

        Schema::table('estimate_items', function (Blueprint $table) {
            $table->string('name', 255)->change();
        });
    }
};
