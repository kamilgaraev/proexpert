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
            $table->decimal('vat_rate', 5, 2)->default(0)->change();
            $table->decimal('overhead_rate', 5, 2)->default(0)->change();
            $table->decimal('profit_rate', 5, 2)->default(0)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('estimates', function (Blueprint $table) {
            $table->decimal('vat_rate', 5, 2)->default(20)->change();
            $table->decimal('overhead_rate', 5, 2)->default(15)->change();
            $table->decimal('profit_rate', 5, 2)->default(12)->change();
        });
    }
};
