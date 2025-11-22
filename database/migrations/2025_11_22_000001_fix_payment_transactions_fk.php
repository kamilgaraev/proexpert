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
        Schema::table('payment_transactions', function (Blueprint $table) {
            // Drop the foreign key constraint if it exists
            // We use a try-catch block or check existence to avoid errors if it's already gone
            // But Laravel's schema builder doesn't have a clean "dropForeignKeyIfExists"
            // So we'll just try to drop it.
            
            // Note: The constraint name is usually table_column_foreign
            $table->dropForeign(['invoice_id']);
            
            // We also make the column nullable if it isn't already, just in case
            $table->unsignedBigInteger('invoice_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            // We cannot easily restore the FK because we might have data that violates it now
            // But for correctness of down(), we would try to add it back
            // $table->foreign('invoice_id')->references('id')->on('invoices');
        });
    }
};
