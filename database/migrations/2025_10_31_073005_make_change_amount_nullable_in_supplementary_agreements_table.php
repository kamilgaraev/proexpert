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
        Schema::table('supplementary_agreements', function (Blueprint $table) {
            $table->decimal('change_amount', 18, 2)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('supplementary_agreements', function (Blueprint $table) {
            // Восстанавливаем NOT NULL с default(0)
            // Сначала обновляем все NULL на 0
            \Illuminate\Support\Facades\DB::statement('UPDATE supplementary_agreements SET change_amount = 0 WHERE change_amount IS NULL');
            $table->decimal('change_amount', 18, 2)->default(0)->change();
        });
    }
};
