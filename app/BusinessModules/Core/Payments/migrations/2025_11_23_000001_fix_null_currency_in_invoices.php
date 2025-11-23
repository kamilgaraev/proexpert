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
        // Обновить все записи с NULL currency на RUB
        DB::table('invoices')
            ->whereNull('currency')
            ->update(['currency' => 'RUB']);
            
        // Убедиться, что колонка currency имеет NOT NULL constraint
        DB::statement('ALTER TABLE invoices ALTER COLUMN currency SET NOT NULL');
        
        // Убедиться, что дефолт установлен на уровне БД
        DB::statement("ALTER TABLE invoices ALTER COLUMN currency SET DEFAULT 'RUB'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Откатить NOT NULL constraint
        DB::statement('ALTER TABLE invoices ALTER COLUMN currency DROP NOT NULL');
    }
};

