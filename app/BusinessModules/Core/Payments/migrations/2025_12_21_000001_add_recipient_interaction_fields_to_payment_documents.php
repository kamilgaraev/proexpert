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
            // Кэш ID организации-получателя для быстрого поиска
            $table->foreignId('recipient_organization_id')
                ->nullable()
                ->after('payee_contractor_id')
                ->constrained('organizations')
                ->onDelete('set null');
            
            // Отслеживание взаимодействия с получателем
            $table->timestamp('recipient_notified_at')->nullable()->after('overdue_since');
            $table->timestamp('recipient_viewed_at')->nullable()->after('recipient_notified_at');
            $table->timestamp('recipient_confirmed_at')->nullable()->after('recipient_viewed_at');
            $table->text('recipient_confirmation_comment')->nullable()->after('recipient_confirmed_at');
            $table->foreignId('recipient_confirmed_by_user_id')
                ->nullable()
                ->after('recipient_confirmation_comment')
                ->constrained('users')
                ->onDelete('set null');
            
            // Индексы для быстрого поиска входящих документов
            $table->index('recipient_organization_id');
            $table->index(['recipient_organization_id', 'status']);
            $table->index('recipient_confirmed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_documents', function (Blueprint $table) {
            $table->dropForeign(['recipient_organization_id']);
            $table->dropForeign(['recipient_confirmed_by_user_id']);
            $table->dropIndex(['recipient_organization_id']);
            $table->dropIndex(['recipient_organization_id', 'status']);
            $table->dropIndex(['recipient_confirmed_at']);
            
            $table->dropColumn([
                'recipient_organization_id',
                'recipient_notified_at',
                'recipient_viewed_at',
                'recipient_confirmed_at',
                'recipient_confirmation_comment',
                'recipient_confirmed_by_user_id',
            ]);
        });
    }
};

