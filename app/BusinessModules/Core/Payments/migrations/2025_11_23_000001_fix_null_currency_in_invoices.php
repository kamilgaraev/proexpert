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
            
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE invoices ALTER COLUMN currency SET NOT NULL');
            DB::statement("ALTER TABLE invoices ALTER COLUMN currency SET DEFAULT 'RUB'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE invoices ALTER COLUMN currency DROP NOT NULL');
        }
    }
};

