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
        Schema::dropIfExists('invoices');
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

