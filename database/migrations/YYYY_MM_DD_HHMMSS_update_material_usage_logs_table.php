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
        Schema::table('material_usage_logs', function (Blueprint $table) {
            // Добавляем недостающие колонки, если их еще нет
            if (!Schema::hasColumn('material_usage_logs', 'organization_id')) {
                $table->foreignId('organization_id')->after('user_id')->nullable()->constrained('organizations')->onDelete('cascade');
            }
            if (!Schema::hasColumn('material_usage_logs', 'operation_type')) {
                $table->string('operation_type')->after('organization_id')->default('write_off')->comment('receipt, write_off');
            }
            if (!Schema::hasColumn('material_usage_logs', 'unit_price')) {
                $table->decimal('unit_price', 10, 2)->nullable()->after('quantity');
            }
            if (!Schema::hasColumn('material_usage_logs', 'total_price')) {
                $table->decimal('total_price', 12, 2)->nullable()->after('unit_price');
            }
            if (!Schema::hasColumn('material_usage_logs', 'supplier_id')) {
                $table->foreignId('supplier_id')->nullable()->after('total_price')->constrained('suppliers')->onDelete('set null');
            }
            if (!Schema::hasColumn('material_usage_logs', 'document_number')) {
                // Поле document_number было в старой схеме OpenAPI, но invoice_number в новой. Оставляем document_number как более общее.
                $table->string('document_number', 100)->nullable()->after('supplier_id'); 
            }
            if (!Schema::hasColumn('material_usage_logs', 'invoice_date')) {
                $table->date('invoice_date')->nullable()->after('document_number');
            }
            if (!Schema::hasColumn('material_usage_logs', 'photo_path')) {
                $table->string('photo_path')->nullable()->after('invoice_date');
            }
            if (!Schema::hasColumn('material_usage_logs', 'work_type_id')) {
                $table->foreignId('work_type_id')->nullable()->after('notes')->constrained('work_types')->onDelete('set null');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('material_usage_logs', function (Blueprint $table) {
            // Порядок удаления важен для внешних ключей
            if (Schema::hasColumn('material_usage_logs', 'work_type_id')) {
                 // Сначала удаляем внешний ключ, если он есть и назван стандартно Laravel
                if (DB::getDriverName() !== 'sqlite') { // SQLite не поддерживает dropForeign
                    try { $table->dropForeign(['work_type_id']); } catch (\Exception $e) { /* Игнорируем, если ключа нет */ }
                }
                $table->dropColumn('work_type_id');
            }
            if (Schema::hasColumn('material_usage_logs', 'photo_path')) {
                $table->dropColumn('photo_path');
            }
            if (Schema::hasColumn('material_usage_logs', 'invoice_date')) {
                $table->dropColumn('invoice_date');
            }
            if (Schema::hasColumn('material_usage_logs', 'document_number')) {
                $table->dropColumn('document_number');
            }
            if (Schema::hasColumn('material_usage_logs', 'supplier_id')) {
                if (DB::getDriverName() !== 'sqlite') {
                    try { $table->dropForeign(['supplier_id']); } catch (\Exception $e) { /* Игнорируем */ }
                }
                $table->dropColumn('supplier_id');
            }
            if (Schema::hasColumn('material_usage_logs', 'total_price')) {
                $table->dropColumn('total_price');
            }
            if (Schema::hasColumn('material_usage_logs', 'unit_price')) {
                $table->dropColumn('unit_price');
            }
            if (Schema::hasColumn('material_usage_logs', 'operation_type')) {
                $table->dropColumn('operation_type');
            }
            if (Schema::hasColumn('material_usage_logs', 'organization_id')) {
                 if (DB::getDriverName() !== 'sqlite') {
                    try { $table->dropForeign(['organization_id']); } catch (\Exception $e) { /* Игнорируем */ }
                }
                $table->dropColumn('organization_id');
            }
        });
    }
}; 