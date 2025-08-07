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
        Schema::table('site_requests', function (Blueprint $table) {
            // Поля для заявок на персонал
            $table->string('personnel_type')->nullable()->after('notes')->comment('Тип персонала: рабочий, специалист, техника');
            $table->integer('personnel_count')->nullable()->after('personnel_type')->comment('Количество требуемых людей');
            $table->text('personnel_requirements')->nullable()->after('personnel_count')->comment('Требования к персоналу: квалификация, опыт, навыки');
            $table->decimal('hourly_rate', 8, 2)->nullable()->after('personnel_requirements')->comment('Почасовая ставка');
            $table->integer('work_hours_per_day')->nullable()->after('hourly_rate')->comment('Количество рабочих часов в день');
            $table->date('work_start_date')->nullable()->after('work_hours_per_day')->comment('Дата начала работ');
            $table->date('work_end_date')->nullable()->after('work_start_date')->comment('Дата окончания работ');
            $table->text('work_location')->nullable()->after('work_end_date')->comment('Место выполнения работ');
            $table->text('additional_conditions')->nullable()->after('work_location')->comment('Дополнительные условия: проживание, питание, транспорт');
            
            // Индексы для оптимизации поиска
            $table->index('personnel_type');
            $table->index('work_start_date');
            $table->index('work_end_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('site_requests', function (Blueprint $table) {
            $table->dropIndex(['personnel_type']);
            $table->dropIndex(['work_start_date']);
            $table->dropIndex(['work_end_date']);
            
            $table->dropColumn([
                'personnel_type',
                'personnel_count',
                'personnel_requirements',
                'hourly_rate',
                'work_hours_per_day',
                'work_start_date',
                'work_end_date',
                'work_location',
                'additional_conditions'
            ]);
        });
    }
};