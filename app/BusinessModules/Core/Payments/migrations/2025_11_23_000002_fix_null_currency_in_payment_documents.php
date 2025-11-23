<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Проверить существование таблицы
        if (!DB::getSchemaBuilder()->hasTable('payment_documents')) {
            return;
        }
        
        // Обновить все записи с NULL currency на RUB
        DB::table('payment_documents')
            ->whereNull('currency')
            ->update(['currency' => 'RUB']);
            
        // Убедиться, что колонка currency имеет NOT NULL constraint
        DB::statement('ALTER TABLE payment_documents ALTER COLUMN currency SET NOT NULL');
        
        // Убедиться, что дефолт установлен на уровне БД
        DB::statement("ALTER TABLE payment_documents ALTER COLUMN currency SET DEFAULT 'RUB'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!DB::getSchemaBuilder()->hasTable('payment_documents')) {
            return;
        }
        
        // Откатить NOT NULL constraint
        DB::statement('ALTER TABLE payment_documents ALTER COLUMN currency DROP NOT NULL');
    }
};

