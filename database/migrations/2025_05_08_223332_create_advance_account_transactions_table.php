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
        Schema::create('advance_account_transactions', function (Blueprint $table) {
            $table->id();
            
            // Связь с пользователем (прорабом)
            $table->unsignedBigInteger('user_id')->comment('ID пользователя (прораба)');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            
            // Связь с организацией
            $table->unsignedBigInteger('organization_id')->comment('ID организации');
            $table->foreign('organization_id')->references('id')->on('organizations')->onDelete('cascade');
            
            // Связь с проектом (опционально)
            $table->unsignedBigInteger('project_id')->nullable()->comment('ID проекта, к которому относится транзакция');
            $table->foreign('project_id')->references('id')->on('projects')->onDelete('set null');
            
            // Тип транзакции: issue (выдача), expense (списание/расход), return (возврат)
            $table->enum('type', ['issue', 'expense', 'return'])->comment('Тип транзакции: выдача, расход, возврат');
            
            // Сумма транзакции
            $table->decimal('amount', 10, 2)->comment('Сумма транзакции');
            
            // Описание транзакции
            $table->string('description')->nullable()->comment('Описание транзакции');
            
            // Номер документа (номер ордера, чека и т.д.)
            $table->string('document_number')->nullable()->comment('Номер документа (ордера, чека)');
            
            // Дата документа
            $table->date('document_date')->nullable()->comment('Дата документа');
            
            // Баланс подотчетных средств после транзакции
            $table->decimal('balance_after', 10, 2)->comment('Баланс после транзакции');
            
            // Статус отчетности: pending (в ожидании), reported (отчитано), approved (утверждено)
            $table->enum('reporting_status', ['pending', 'reported', 'approved'])->default('pending')->comment('Статус отчетности');
            
            // Дата создания отчета
            $table->timestamp('reported_at')->nullable()->comment('Дата создания отчета');
            
            // Дата утверждения отчета
            $table->timestamp('approved_at')->nullable()->comment('Дата утверждения отчета');
            
            // ID пользователя, создавшего транзакцию (бухгалтер, администратор)
            $table->unsignedBigInteger('created_by_user_id')->nullable()->comment('ID пользователя, создавшего транзакцию');
            $table->foreign('created_by_user_id')->references('id')->on('users')->onDelete('set null');
            
            // ID пользователя, утвердившего транзакцию
            $table->unsignedBigInteger('approved_by_user_id')->nullable()->comment('ID пользователя, утвердившего транзакцию');
            $table->foreign('approved_by_user_id')->references('id')->on('users')->onDelete('set null');
            
            // Внешний код для интеграции с СБИС/1С
            $table->string('external_code')->nullable()->comment('Внешний код для интеграции с СБИС/1С');
            
            // Дополнительные данные для интеграции
            $table->json('accounting_data')->nullable()->comment('Дополнительные данные для интеграции');
            
            // Прикрепленные файлы (ID файлов через запятую)
            $table->string('attachment_ids')->nullable()->comment('ID прикрепленных файлов через запятую');
            
            // Метки времени
            $table->timestamps();
            $table->softDeletes();
            
            // Индексы
            $table->index('user_id');
            $table->index('organization_id');
            $table->index('project_id');
            $table->index('type');
            $table->index('reporting_status');
            $table->index('document_date');
            $table->index('external_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('advance_account_transactions');
    }
}; 