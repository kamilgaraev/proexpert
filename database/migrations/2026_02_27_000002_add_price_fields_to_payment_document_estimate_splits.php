<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_document_estimate_splits', function (Blueprint $table) {
            $table->decimal('quantity', 12, 8)->nullable()->after('estimate_item_id')
                ->comment('Количество единиц');
            $table->decimal('unit_price_plan', 12, 4)->nullable()->after('quantity')
                ->comment('Плановая цена из сметы на момент создания платежа');
            $table->decimal('unit_price_actual', 12, 4)->nullable()->after('unit_price_plan')
                ->comment('Фактическая цена закупки');
            $table->decimal('price_deviation', 14, 2)->nullable()->after('unit_price_actual')
                ->comment('Отклонение = (actual - plan) * quantity');
        });
    }

    public function down(): void
    {
        Schema::table('payment_document_estimate_splits', function (Blueprint $table) {
            $table->dropColumn(['quantity', 'unit_price_plan', 'unit_price_actual', 'price_deviation']);
        });
    }
};
