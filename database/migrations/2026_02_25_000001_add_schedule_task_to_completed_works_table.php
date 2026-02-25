<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('completed_works', function (Blueprint $table) {
            $table->foreignId('schedule_task_id')
                ->nullable()
                ->after('project_id')
                ->constrained('schedule_tasks')
                ->nullOnDelete();

            $table->decimal('completed_quantity', 12, 4)
                ->nullable()
                ->after('quantity')
                ->comment('Объём выполнения именно в этой записи (для частичных выполнений)');

            $table->index('schedule_task_id', 'idx_completed_works_schedule_task');
        });
    }

    public function down(): void
    {
        Schema::table('completed_works', function (Blueprint $table) {
            $table->dropForeign(['schedule_task_id']);
            $table->dropIndex('idx_completed_works_schedule_task');
            $table->dropColumn(['schedule_task_id', 'completed_quantity']);
        });
    }
};
