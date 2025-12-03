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
        Schema::table('invoices', function (Blueprint $table) {
            // Банковские реквизиты получателя платежа
            $table->string('bank_account', 20)->nullable()->after('payment_terms');
            $table->string('bank_bik', 9)->nullable()->after('bank_account');
            $table->string('bank_name')->nullable()->after('bank_bik');
            $table->string('bank_correspondent_account', 20)->nullable()->after('bank_name');
            
            // Индексы для поиска
            $table->index('bank_bik');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['bank_bik']);
            $table->dropColumn([
                'bank_account',
                'bank_bik',
                'bank_name',
                'bank_correspondent_account',
            ]);
        });
    }
};


















