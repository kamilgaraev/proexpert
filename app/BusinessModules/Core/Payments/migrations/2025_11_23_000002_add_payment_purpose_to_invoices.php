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
            // Назначение платежа
            $table->string('payment_purpose', 500)->nullable()->after('payment_terms');
            
            // Банковские реквизиты (если их еще нет)
            if (!Schema::hasColumn('invoices', 'bank_account')) {
                $table->string('bank_account', 20)->nullable()->after('payment_purpose');
                $table->string('bank_bik', 9)->nullable()->after('bank_account');
                $table->string('bank_name', 255)->nullable()->after('bank_bik');
                $table->string('bank_correspondent_account', 20)->nullable()->after('bank_name');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn([
                'payment_purpose',
            ]);
            
            // Удалить банковские реквизиты если они были добавлены этой миграцией
            if (Schema::hasColumn('invoices', 'bank_account')) {
                $table->dropColumn([
                    'bank_account',
                    'bank_bik',
                    'bank_name',
                    'bank_correspondent_account',
                ]);
            }
        });
    }
};

