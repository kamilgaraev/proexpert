<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('customer')->nullable()->after('description');
            $table->string('designer')->nullable()->after('customer');
            $table->decimal('budget_amount', 13, 2)->nullable()->after('designer');
            $table->decimal('site_area_m2', 12, 2)->nullable()->after('budget_amount');
            $table->string('contract_number', 100)->nullable()->after('site_area_m2');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['customer', 'designer', 'budget_amount', 'site_area_m2', 'contract_number']);
        });
    }
}; 