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
     * Удаление таблицы invoices после миграции всех данных в payment_documents
     * ВАЖНО: Эта миграция должна выполняться ПОСЛЕ удаления всех внешних ключей
     */
    public function up(): void
    {
        // Проверяем, существует ли таблица invoices
        if (!Schema::hasTable('invoices')) {
            return; // Таблица уже удалена
        }

        // ШАГ 1: Находим и удаляем ВСЕ внешние ключи, которые ссылаются на таблицу invoices
        // Используем системные таблицы PostgreSQL для поиска всех зависимостей
        
        $dependencies = DB::select("
            SELECT 
                tc.table_name,
                tc.constraint_name
            FROM information_schema.table_constraints tc
            JOIN information_schema.referential_constraints rc
                ON tc.constraint_name = rc.constraint_name
                AND tc.table_schema = rc.constraint_schema
            JOIN information_schema.table_constraints tc_ref
                ON rc.unique_constraint_name = tc_ref.constraint_name
                AND rc.unique_constraint_schema = tc_ref.table_schema
            WHERE tc.constraint_type = 'FOREIGN KEY'
            AND tc.table_schema = 'public'
            AND tc_ref.table_name = 'invoices'
        ");

        foreach ($dependencies as $dep) {
            try {
                $constraintName = $dep->constraint_name;
                $tableName = $dep->table_name;
                DB::statement("ALTER TABLE \"{$tableName}\" DROP CONSTRAINT IF EXISTS \"{$constraintName}\" CASCADE");
            } catch (\Exception $e) {
                // Игнорируем ошибки, если FK уже удален или таблица не существует
            }
        }

        // ШАГ 2: Также проверяем через прямой поиск по именам (на случай, если первый способ не сработал)
        // Ищем все таблицы, которые могут иметь FK на invoices
        $tablesToCheck = ['payment_transactions', 'payment_schedules'];
        
        foreach ($tablesToCheck as $tableName) {
            if (!Schema::hasTable($tableName)) {
                continue;
            }

            $fks = DB::select("
                SELECT constraint_name 
                FROM information_schema.table_constraints 
                WHERE table_schema = 'public'
                AND table_name = ?
                AND constraint_type = 'FOREIGN KEY'
                AND constraint_name LIKE '%invoice_id%'
            ", [$tableName]);

            foreach ($fks as $fk) {
                try {
                    $constraintName = $fk->constraint_name;
                    DB::statement("ALTER TABLE \"{$tableName}\" DROP CONSTRAINT IF EXISTS \"{$constraintName}\" CASCADE");
                } catch (\Exception $e) {
                    // Игнорируем ошибки
                }
            }
        }

        // ШАГ 3: Удаляем таблицу invoices с CASCADE для безопасности
        // CASCADE автоматически удалит все оставшиеся зависимости
        DB::statement('DROP TABLE IF EXISTS invoices CASCADE');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Восстановление таблицы invoices (для rollback)
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            
            // Организации
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('counterparty_organization_id')->nullable()->constrained('organizations')->onDelete('set null');
            $table->foreignId('contractor_id')->nullable()->constrained()->onDelete('set null');
            
            // Проект (опционально)
            $table->foreignId('project_id')->nullable()->constrained()->onDelete('cascade');
            
            // Polymorphic связь
            $table->string('invoiceable_type')->nullable();
            $table->unsignedBigInteger('invoiceable_id')->nullable();
            
            // Основная информация
            $table->string('invoice_number')->unique();
            $table->date('invoice_date');
            $table->date('due_date');
            $table->string('direction'); // incoming/outgoing
            $table->string('invoice_type'); // act, advance, progress, etc
            
            // Суммы
            $table->decimal('total_amount', 15, 2);
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->decimal('remaining_amount', 15, 2);
            $table->string('currency', 3)->default('RUB');
            
            // НДС
            $table->decimal('vat_rate', 5, 2)->nullable();
            $table->decimal('vat_amount', 15, 2)->nullable();
            $table->decimal('amount_without_vat', 15, 2)->nullable();
            
            // Статус
            $table->string('status')->default('draft');
            
            // Дополнительно
            $table->text('description')->nullable();
            $table->text('payment_terms')->nullable();
            $table->json('metadata')->nullable();
            $table->text('notes')->nullable();
            
            // Даты
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('overdue_since')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            // Индексы
            $table->index(['organization_id', 'status']);
            $table->index(['project_id', 'status']);
            $table->index(['due_date', 'status']);
            $table->index(['invoiceable_type', 'invoiceable_id']);
            $table->index(['counterparty_organization_id', 'direction']);
            $table->index(['invoice_date']);
        });
    }
};

