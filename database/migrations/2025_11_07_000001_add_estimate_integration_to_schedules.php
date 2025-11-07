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
        // Добавляем поля интеграции со сметой в project_schedules
        Schema::table('project_schedules', function (Blueprint $table) {
            $table->foreignId('estimate_id')
                ->nullable()
                ->after('project_id')
                ->constrained('estimates')
                ->onDelete('set null')
                ->comment('Связь со сметой');
            
            $table->boolean('sync_with_estimate')
                ->default(true)
                ->after('estimate_id')
                ->comment('Включена ли синхронизация со сметой');
            
            $table->timestamp('last_synced_at')
                ->nullable()
                ->after('sync_with_estimate')
                ->comment('Дата последней синхронизации');
            
            $table->enum('sync_status', ['synced', 'out_of_sync', 'conflict'])
                ->default('synced')
                ->after('last_synced_at')
                ->comment('Статус синхронизации со сметой');
            
            // Индекс для быстрого поиска графиков по смете
            $table->index('estimate_id');
            $table->index('sync_status');
        });

        // Добавляем поля интеграции со сметой в schedule_tasks
        Schema::table('schedule_tasks', function (Blueprint $table) {
            $table->foreignId('estimate_item_id')
                ->nullable()
                ->after('parent_task_id')
                ->constrained('estimate_items')
                ->onDelete('set null')
                ->comment('Связь с позицией сметы');
            
            $table->foreignId('estimate_section_id')
                ->nullable()
                ->after('estimate_item_id')
                ->constrained('estimate_sections')
                ->onDelete('set null')
                ->comment('Связь с разделом сметы');
            
            $table->decimal('quantity', 10, 4)
                ->nullable()
                ->after('planned_work_hours')
                ->comment('Объем работ из сметы');
            
            $table->foreignId('measurement_unit_id')
                ->nullable()
                ->after('quantity')
                ->constrained('measurement_units')
                ->onDelete('set null')
                ->comment('Единица измерения объема');
            
            $table->decimal('labor_hours_from_estimate', 10, 2)
                ->nullable()
                ->after('measurement_unit_id')
                ->comment('Трудозатраты из сметы (чел-час)');
            
            $table->decimal('resource_cost', 15, 2)
                ->nullable()
                ->after('labor_hours_from_estimate')
                ->comment('Стоимость ресурсов из сметы');
            
            // Индексы для быстрого поиска задач по смете
            $table->index('estimate_item_id');
            $table->index('estimate_section_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('schedule_tasks', function (Blueprint $table) {
            $table->dropForeign(['estimate_item_id']);
            $table->dropForeign(['estimate_section_id']);
            $table->dropForeign(['measurement_unit_id']);
            $table->dropIndex(['estimate_item_id']);
            $table->dropIndex(['estimate_section_id']);
            
            $table->dropColumn([
                'estimate_item_id',
                'estimate_section_id',
                'quantity',
                'measurement_unit_id',
                'labor_hours_from_estimate',
                'resource_cost',
            ]);
        });

        Schema::table('project_schedules', function (Blueprint $table) {
            $table->dropForeign(['estimate_id']);
            $table->dropIndex(['estimate_id']);
            $table->dropIndex(['sync_status']);
            
            $table->dropColumn([
                'estimate_id',
                'sync_with_estimate',
                'last_synced_at',
                'sync_status',
            ]);
        });
    }
};

