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
        Schema::create('construction_journals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete()
                ->comment('Организация');
            $table->foreignId('project_id')
                ->constrained('projects')
                ->cascadeOnDelete()
                ->comment('Проект');
            $table->foreignId('contract_id')
                ->nullable()
                ->constrained('contracts')
                ->nullOnDelete()
                ->comment('Договор (необязательно)');
            $table->string('name')
                ->comment('Название журнала');
            $table->string('journal_number')
                ->nullable()
                ->comment('Номер журнала');
            $table->date('start_date')
                ->comment('Дата начала');
            $table->date('end_date')
                ->nullable()
                ->comment('Дата окончания');
            $table->enum('status', ['active', 'archived', 'closed'])
                ->default('active')
                ->comment('Статус журнала');
            $table->foreignId('created_by_user_id')
                ->constrained('users')
                ->cascadeOnDelete()
                ->comment('Создатель журнала');
            $table->timestamps();
            $table->softDeletes();

            // Индексы
            $table->index('organization_id');
            $table->index('project_id');
            $table->index('contract_id');
            $table->index('status');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('construction_journals');
    }
};

