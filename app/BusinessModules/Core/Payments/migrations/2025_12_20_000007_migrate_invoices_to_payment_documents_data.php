<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Миграция данных из invoices в payment_documents
     */
    public function up(): void
    {
        // Миграция выполняется через команду payments:migrate-invoices-to-documents
        // Эта миграция только создает структуру для миграции данных
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Откат миграции данных не предусмотрен
        // Данные должны быть восстановлены из резервной копии
    }
};

