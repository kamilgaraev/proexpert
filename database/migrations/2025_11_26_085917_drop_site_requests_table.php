<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Удаляем таблицу site_requests, так как функциональность заявок с объекта
     * была удалена из ядра и будет перенесена в отдельный модуль.
     */
    public function up(): void
    {
        Schema::dropIfExists('site_requests');
    }

    /**
     * Reverse the migrations.
     * 
     * Восстанавливаем таблицу на случай отката миграции.
     * ВАЖНО: Восстановится только структура таблицы, данные будут потеряны!
     */
    public function down(): void
    {
        Schema::create('site_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->foreignId('project_id')->constrained('projects')->onDelete('cascade');
            $table->foreignId('user_id')->comment('ID пользователя (прораба), создавшего заявку')->constrained('users')->onDelete('cascade');
            
            $table->string('title');
            $table->text('description')->nullable();
            
            $table->string('status')->default('draft');
            $table->string('priority')->default('medium');
            $table->string('request_type')->default('other');
            
            $table->date('required_date')->nullable()->comment('Желаемая дата исполнения/получения');
            $table->text('notes')->nullable()->comment('Дополнительные примечания или комментарии к заявке');
            
            // Поля для заявок на персонал
            $table->string('personnel_type')->nullable()->comment('Тип персонала: рабочий, специалист, техника');
            $table->integer('personnel_count')->nullable()->comment('Количество требуемых людей');
            $table->text('personnel_requirements')->nullable()->comment('Требования к персоналу: квалификация, опыт, навыки');
            $table->decimal('hourly_rate', 8, 2)->nullable()->comment('Почасовая ставка');
            $table->integer('work_hours_per_day')->nullable()->comment('Количество рабочих часов в день');
            $table->date('work_start_date')->nullable()->comment('Дата начала работ');
            $table->date('work_end_date')->nullable()->comment('Дата окончания работ');
            $table->text('work_location')->nullable()->comment('Место выполнения работ');
            $table->text('additional_conditions')->nullable()->comment('Дополнительные условия: проживание, питание, транспорт');
            
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('priority');
            $table->index('request_type');
            $table->index('required_date');
            $table->index('personnel_type');
            $table->index('work_start_date');
            $table->index('work_end_date');
        });
    }
};
