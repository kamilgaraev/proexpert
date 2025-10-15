<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->string('gp_calculation_type', 50)->default('percentage')->after('gp_percentage');
            $table->decimal('gp_coefficient', 10, 4)->nullable()->after('gp_calculation_type');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn(['gp_calculation_type', 'gp_coefficient']);
        });
    }
};

