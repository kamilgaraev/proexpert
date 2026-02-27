<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estimate_items', function (Blueprint $table) {
            $table->decimal('actual_unit_price', 12, 4)->nullable()->after('current_unit_price')
                ->comment('Фактическая цена закупки');
            $table->decimal('actual_quantity', 12, 8)->nullable()->after('actual_unit_price')
                ->comment('Фактически закупленное количество');
            $table->string('procurement_status', 50)->notNull()->default('pending')->after('actual_quantity')
                ->comment('Статус закупки: pending, ordered, paid, delivered');

            $table->index('procurement_status');
        });
    }

    public function down(): void
    {
        Schema::table('estimate_items', function (Blueprint $table) {
            $table->dropIndex(['procurement_status']);
            $table->dropColumn(['actual_unit_price', 'actual_quantity', 'procurement_status']);
        });
    }
};
