<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_documents', function (Blueprint $table): void {
            $table->string('schedule_source', 30)->nullable();
        });

        Schema::table('payment_schedules', function (Blueprint $table): void {
            $table->string('source_key', 160)->nullable();
            $table->unique(['payment_document_id', 'source_key'], 'payment_schedules_document_source_unique');
            $table->date('due_date')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('payment_documents')->whereNotNull('schedule_source')->exists()
            || DB::table('payment_schedules')->whereNotNull('source_key')->orWhereNull('due_date')->exists()) {
            throw new LogicException('Cannot remove settlement identity while managed or undated installments exist.');
        }

        Schema::table('payment_schedules', function (Blueprint $table): void {
            $table->dropUnique('payment_schedules_document_source_unique');
            $table->dropColumn('source_key');
            $table->date('due_date')->nullable(false)->change();
        });

        Schema::table('payment_documents', function (Blueprint $table): void {
            $table->dropColumn('schedule_source');
        });
    }
};
