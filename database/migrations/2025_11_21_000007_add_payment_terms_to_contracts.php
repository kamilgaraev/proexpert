<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Добавляет поля условий оплаты в таблицу contracts
     */
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            // Срок оплаты в днях (по умолчанию 14 дней)
            if (!Schema::hasColumn('contracts', 'payment_terms_days')) {
                $table->integer('payment_terms_days')->default(14)->after('contract_date');
            }
            
            // JSON с детальными условиями оплаты
            if (!Schema::hasColumn('contracts', 'payment_terms')) {
                $table->json('payment_terms')->nullable()->after('payment_terms_days');
            }
            
            // Процент аванса (если есть)
            if (!Schema::hasColumn('contracts', 'advance_payment_percent')) {
                $table->decimal('advance_payment_percent', 5, 2)->default(0)->after('payment_terms');
            }
            
            // Автосоздание счетов при подписании актов
            if (!Schema::hasColumn('contracts', 'auto_create_invoices')) {
                $table->boolean('auto_create_invoices')->default(true)->after('advance_payment_percent');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn([
                'payment_terms_days',
                'payment_terms',
                'advance_payment_percent',
                'auto_create_invoices',
            ]);
        });
    }
};

