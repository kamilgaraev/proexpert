<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = 'payment_document_estimate_splits';
        if (! Schema::hasTable($tableName)) {
            throw new RuntimeException('Payment document estimate split table is missing.');
        }

        $existing = Schema::getColumnListing($tableName);
        $missing = array_diff(['quantity', 'unit_price_plan', 'unit_price_actual', 'price_deviation'], $existing);
        if ($missing === []) {
            return;
        }

        Schema::table($tableName, static function (Blueprint $table) use ($missing): void {
            if (in_array('quantity', $missing, true)) {
                $table->decimal('quantity', 12, 8)->nullable();
            }
            if (in_array('unit_price_plan', $missing, true)) {
                $table->decimal('unit_price_plan', 12, 4)->nullable();
            }
            if (in_array('unit_price_actual', $missing, true)) {
                $table->decimal('unit_price_actual', 12, 4)->nullable();
            }
            if (in_array('price_deviation', $missing, true)) {
                $table->decimal('price_deviation', 14, 2)->nullable();
            }
        });
    }

    public function down(): void {}
};
