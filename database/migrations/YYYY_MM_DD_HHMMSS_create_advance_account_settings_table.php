<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('advance_account_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            
            $table->decimal('max_single_issue_amount', 15, 2)->nullable()->comment('Максимальная сумма для одной транзакции выдачи');
            $table->integer('report_submission_deadline_days')->nullable()->comment('Срок в днях для подачи отчета');
            $table->decimal('dual_authorization_threshold', 15, 2)->nullable()->comment('Порог для двойной авторизации транзакций');
            $table->boolean('require_project_for_expense')->default(false)->comment('Требовать указание проекта для расходов');
            
            $table->boolean('notify_admin_on_overdue_report')->default(false)->comment('Уведомлять админа о просроченных отчетах');
            $table->boolean('notify_admin_on_high_value_transaction')->default(false)->comment('Уведомлять админа о крупных транзакциях');
            $table->decimal('high_value_notification_threshold', 15, 2)->nullable()->comment('Порог для уведомления о крупной транзакции');
            $table->boolean('notify_user_on_transaction_approval')->default(true)->comment('Уведомлять пользователя об утверждении транзакции');
            $table->boolean('notify_user_on_transaction_rejection')->default(true)->comment('Уведомлять пользователя об отклонении транзакции');
            $table->integer('send_report_reminder_days_before')->nullable()->comment('За сколько дней до срока отправлять напоминание об отчете');
            
            $table->timestamps();

            // Уникальный ключ для organization_id, чтобы у каждой организации был только один набор настроек
            $table->unique(['organization_id'], 'advance_account_settings_organization_unique');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('advance_account_settings');
    }
}; 