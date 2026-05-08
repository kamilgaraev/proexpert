<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estimate_resource_prices', function (Blueprint $table): void {
            $table->decimal('machine_salary_price', 20, 4)->nullable()->after('base_price');
            $table->decimal('machine_price_without_salary', 20, 4)->nullable()->after('machine_salary_price');
            $table->decimal('machine_labor_quantity', 20, 6)->nullable()->after('machine_price_without_salary');
            $table->string('driver_code', 100)->nullable()->after('machine_labor_quantity');
            $table->string('machinist_category', 50)->nullable()->after('driver_code');
            $table->string('source_price_kind', 50)->nullable()->after('price_type');
        });
    }

    public function down(): void
    {
        Schema::table('estimate_resource_prices', function (Blueprint $table): void {
            $table->dropColumn([
                'machine_salary_price',
                'machine_price_without_salary',
                'machine_labor_quantity',
                'driver_code',
                'machinist_category',
                'source_price_kind',
            ]);
        });
    }
};
