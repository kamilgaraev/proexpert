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
        Schema::table('payment_documents', function (Blueprint $table) {
            // Направление платежа (incoming/outgoing)
            $table->string('direction')->nullable()->after('document_type');
            
            // Тип счета (для документов типа invoice)
            $table->string('invoice_type')->nullable()->after('direction');
            
            // Polymorphic связь с источниками (акты, договоры, сметы)
            $table->string('invoiceable_type')->nullable()->after('invoice_type');
            $table->unsignedBigInteger('invoiceable_id')->nullable()->after('invoiceable_type');
            
            // Контрагент-организация (альтернатива payer/payee)
            $table->foreignId('counterparty_organization_id')
                ->nullable()
                ->after('payee_contractor_id')
                ->constrained('organizations')
                ->onDelete('set null');
            
            // Контрагент-подрядчик (альтернатива payer/payee)
            $table->foreignId('contractor_id')
                ->nullable()
                ->after('counterparty_organization_id')
                ->constrained('contractors')
                ->onDelete('set null');
            
            // Дата выставления счета
            $table->timestamp('issued_at')->nullable()->after('approved_at');
            
            // Дата начала просрочки
            $table->timestamp('overdue_since')->nullable()->after('issued_at');
            
            // Условия оплаты (дополнительно к payment_purpose)
            $table->text('payment_terms')->nullable()->after('payment_purpose');
            
            // Индексы
            $table->index('direction');
            $table->index('invoice_type');
            $table->index(['invoiceable_type', 'invoiceable_id']);
            $table->index('counterparty_organization_id');
            $table->index('contractor_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_documents', function (Blueprint $table) {
            $table->dropIndex(['contractor_id']);
            $table->dropIndex(['counterparty_organization_id']);
            $table->dropIndex(['invoiceable_type', 'invoiceable_id']);
            $table->dropIndex(['invoice_type']);
            $table->dropIndex(['direction']);
            
            $table->dropForeign(['contractor_id']);
            $table->dropForeign(['counterparty_organization_id']);
            
            $table->dropColumn([
                'direction',
                'invoice_type',
                'invoiceable_type',
                'invoiceable_id',
                'counterparty_organization_id',
                'contractor_id',
                'issued_at',
                'overdue_since',
                'payment_terms',
            ]);
        });
    }
};

