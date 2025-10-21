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
            $table->decimal('quantity_coefficient', 10, 4)->nullable()->after('quantity');
            $table->decimal('quantity_total', 15, 4)->nullable()->after('quantity_coefficient');
            
            $table->decimal('base_unit_price', 15, 2)->nullable()->after('unit_price');
            $table->decimal('price_index', 10, 4)->nullable()->after('base_unit_price');
            $table->decimal('current_unit_price', 15, 2)->nullable()->after('price_index');
            $table->decimal('price_coefficient', 10, 4)->nullable()->after('current_unit_price');
            $table->decimal('current_total_amount', 15, 2)->nullable()->after('total_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('estimate_items', function (Blueprint $table) {
            $table->dropColumn([
                'quantity_coefficient',
                'quantity_total',
                'base_unit_price',
                'price_index',
                'current_unit_price',
                'price_coefficient',
                'current_total_amount',
            ]);
        });
    }
};
