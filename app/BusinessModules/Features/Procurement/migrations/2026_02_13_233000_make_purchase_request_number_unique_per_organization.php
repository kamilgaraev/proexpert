<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            // Сначала удаляем глобальный уникальный индекс
            $table->dropUnique(['request_number']);
            // Затем добавляем составной уникальный индекс (организация + номер)
            $table->unique(['organization_id', 'request_number'], 'purchase_requests_org_number_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->dropUnique('purchase_requests_org_number_unique');
            // Внимание: это может упасть, если в базе уже есть дубликаты по request_number разных организаций
            $table->unique(['request_number']);
        });
    }
};
