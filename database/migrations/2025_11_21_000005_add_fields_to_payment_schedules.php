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
        Schema::table('payment_schedules', function (Blueprint $table) {
            // Добавляем колонки если их нет
            if (!Schema::hasColumn('payment_schedules', 'description')) {
                $table->text('description')->nullable()->after('amount');
            }
            
            if (!Schema::hasColumn('payment_schedules', 'paid_amount')) {
                $table->decimal('paid_amount', 15, 2)->default(0)->after('amount');
            }
            
            if (!Schema::hasColumn('payment_schedules', 'metadata')) {
                $table->json('metadata')->nullable()->after('notes');
            }
            
            // Индексы
            if (!Schema::hasColumn('payment_schedules', 'invoice_id')) {
                $table->index('invoice_id');
            }
            if (!Schema::hasColumn('payment_schedules', 'status')) {
                $table->index('status');
            }
            if (!Schema::hasColumn('payment_schedules', 'due_date')) {
                $table->index('due_date');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_schedules', function (Blueprint $table) {
            $table->dropColumn(['description', 'paid_amount', 'metadata']);
        });
    }
};

