<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            if (!Schema::hasColumn('projects', 'customer')) {
                $table->string('customer')->nullable()->after('description');
            }
            if (!Schema::hasColumn('projects', 'designer')) {
                $table->string('designer')->nullable()->after('customer');
            }
            if (!Schema::hasColumn('projects', 'budget_amount')) {
                $table->decimal('budget_amount', 13, 2)->nullable()->after('designer');
            }
            if (!Schema::hasColumn('projects', 'site_area_m2')) {
                $table->decimal('site_area_m2', 12, 2)->nullable()->after('budget_amount');
            }
            if (!Schema::hasColumn('projects', 'contract_number')) {
                $table->string('contract_number', 100)->nullable()->after('site_area_m2');
            }
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['customer', 'designer', 'budget_amount', 'site_area_m2', 'contract_number']);
        });
    }
}; 