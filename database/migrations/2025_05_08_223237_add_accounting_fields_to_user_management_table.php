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
        Schema::table('users', function (Blueprint $table) {
            // Внешний код пользователя для интеграции с бухгалтерией
            $table->string('external_code')->nullable()->comment('Внешний код пользователя из СБИС/1С');
            
            // Табельный номер сотрудника
            $table->string('employee_id')->nullable()->comment('Табельный номер сотрудника');
            
            // Счет учета подотчетных средств
            $table->string('accounting_account')->nullable()->comment('Счет учета подотчетных средств в бухгалтерии');
            
            // Текущий остаток средств у прораба
            $table->decimal('current_balance', 10, 2)->default(0.00)->comment('Текущий остаток подотчетных средств');
            
            // Общая сумма выданных средств
            $table->decimal('total_issued', 10, 2)->default(0.00)->comment('Общая сумма выданных подотчетных средств');
            
            // Общая сумма отчитанных средств
            $table->decimal('total_reported', 10, 2)->default(0.00)->comment('Общая сумма отчитанных подотчетных средств');
            
            // Дата последней транзакции
            $table->timestamp('last_transaction_at')->nullable()->comment('Дата последней транзакции по подотчетным средствам');
            
            // Флаг для отметки пользователей с превышенными подотчетными средствами
            $table->boolean('has_overdue_balance')->default(false)->comment('Флаг превышения срока отчета по подотчетным средствам');
            
            // Дополнительные данные для бухгалтерского учета
            $table->json('accounting_data')->nullable()->comment('Дополнительные данные для интеграции с бухгалтерскими системами');
            
            // Индексы для оптимизации поиска
            $table->index('external_code');
            $table->index('employee_id');
            $table->index('has_overdue_balance');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['external_code']);
            $table->dropIndex(['employee_id']);
            $table->dropIndex(['has_overdue_balance']);
            
            $table->dropColumn('external_code');
            $table->dropColumn('employee_id');
            $table->dropColumn('accounting_account');
            $table->dropColumn('current_balance');
            $table->dropColumn('total_issued');
            $table->dropColumn('total_reported');
            $table->dropColumn('last_transaction_at');
            $table->dropColumn('has_overdue_balance');
            $table->dropColumn('accounting_data');
        });
    }
}; 