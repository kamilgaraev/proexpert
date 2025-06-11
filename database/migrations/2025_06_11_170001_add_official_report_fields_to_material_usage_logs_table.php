<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('material_usage_logs', function (Blueprint $table) {
            $table->decimal('production_norm_quantity', 15, 3)->nullable()->after('quantity');
            $table->decimal('fact_quantity', 15, 3)->nullable()->after('production_norm_quantity');
            $table->decimal('previous_month_balance', 15, 3)->nullable()->after('fact_quantity');
            $table->decimal('current_balance', 15, 3)->nullable()->after('previous_month_balance');
            $table->text('work_description')->nullable()->after('notes');
            $table->string('receipt_document_reference')->nullable()->after('work_description');
        });
    }

    public function down(): void
    {
        Schema::table('material_usage_logs', function (Blueprint $table) {
            $table->dropColumn([
                'production_norm_quantity',
                'fact_quantity', 
                'previous_month_balance',
                'current_balance',
                'work_description',
                'receipt_document_reference'
            ]);
        });
    }
}; 