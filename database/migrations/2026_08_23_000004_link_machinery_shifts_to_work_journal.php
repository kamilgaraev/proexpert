<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machinery_shift_reports', function (Blueprint $table): void {
            $table->foreignId('schedule_task_id')->nullable()->after('assignment_id')->constrained('schedule_tasks')->restrictOnDelete();
            $table->foreignId('construction_journal_entry_id')->nullable()->after('schedule_task_id')->constrained('construction_journal_entries')->restrictOnDelete();
            $table->index(['project_id', 'report_date', 'schedule_task_id'], 'machinery_shift_work_context_index');
        });
    }

    public function down(): void
    {
        Schema::table('machinery_shift_reports', function (Blueprint $table): void {
            $table->dropIndex('machinery_shift_work_context_index');
            $table->dropConstrainedForeignId('construction_journal_entry_id');
            $table->dropConstrainedForeignId('schedule_task_id');
        });
    }
};
