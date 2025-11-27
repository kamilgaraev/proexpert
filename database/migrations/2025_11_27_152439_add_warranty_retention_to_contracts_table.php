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
            $table->string('warranty_retention_calculation_type', 50)->nullable()->default('percentage')->after('gp_coefficient');
            $table->decimal('warranty_retention_percentage', 5, 3)->nullable()->default(0)->after('warranty_retention_calculation_type');
            $table->decimal('warranty_retention_coefficient', 10, 4)->nullable()->after('warranty_retention_percentage');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn([
                'warranty_retention_calculation_type',
                'warranty_retention_percentage',
                'warranty_retention_coefficient',
            ]);
        });
    }
};
