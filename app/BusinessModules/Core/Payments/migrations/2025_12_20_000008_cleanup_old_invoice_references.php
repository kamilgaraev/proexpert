<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Очистка старых ссылок на удаленный класс Invoice
     */
    public function up(): void
    {
        // Обновляем invoiceable_type для старых записей, которые ссылаются на Invoice
        DB::table('payment_documents')
            ->where('invoiceable_type', 'App\\BusinessModules\\Core\\Payments\\Models\\Invoice')
            ->orWhere('invoiceable_type', 'App\\\\BusinessModules\\\\Core\\\\Payments\\\\Models\\\\Invoice')
            ->update([
                'invoiceable_type' => null,
                'invoiceable_id' => null,
            ]);

        // Также обновляем source_type для старых записей
        DB::table('payment_documents')
            ->where('source_type', 'App\\BusinessModules\\Core\\Payments\\Models\\Invoice')
            ->orWhere('source_type', 'App\\\\BusinessModules\\\\Core\\\\Payments\\\\Models\\\\Invoice')
            ->update([
                'source_type' => null,
                'source_id' => null,
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Откат не требуется, так как класс Invoice больше не существует
    }
};

