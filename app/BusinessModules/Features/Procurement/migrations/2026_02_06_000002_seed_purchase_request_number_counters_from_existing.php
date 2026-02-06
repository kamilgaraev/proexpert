<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Инициализация счётчиков из существующих номеров заявок (ЗЗ-YYYYMM-NNNN).
     */
    public function up(): void
    {
        if (!Schema::hasTable('purchase_requests') || !Schema::hasTable('purchase_request_number_counters')) {
            return;
        }

        DB::statement("
            INSERT INTO purchase_request_number_counters (organization_id, year, month, last_number, created_at, updated_at)
            SELECT organization_id,
                   CAST(SUBSTRING(request_number FROM '^..([0-9]{4})') AS INTEGER),
                   CAST(SUBSTRING(request_number FROM '^......([0-9]{2})') AS INTEGER),
                   MAX(CAST(SUBSTRING(request_number FROM '([0-9]+)$') AS INTEGER)),
                   NOW(), NOW()
            FROM purchase_requests
            WHERE request_number ~ '^..[0-9]{6}-[0-9]+$'
            GROUP BY organization_id, SUBSTRING(request_number FROM '^..([0-9]{4})'), SUBSTRING(request_number FROM '^......([0-9]{2})')
            ON CONFLICT (organization_id, year, month) DO UPDATE SET
                last_number = GREATEST(purchase_request_number_counters.last_number, EXCLUDED.last_number),
                updated_at = NOW()
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Не сбрасываем данные — откат только для совместимости
    }
};
