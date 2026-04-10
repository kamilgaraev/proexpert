<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('completed_works', function (Blueprint $table): void {
            if (!Schema::hasColumn('completed_works', 'estimate_item_id')) {
                $table->foreignId('estimate_item_id')
                    ->nullable()
                    ->after('schedule_task_id')
                    ->constrained('estimate_items')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('completed_works', 'journal_entry_id')) {
                $table->foreignId('journal_entry_id')
                    ->nullable()
                    ->after('estimate_item_id')
                    ->constrained('construction_journal_entries')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('completed_works', 'work_origin_type')) {
                $table->string('work_origin_type', 32)
                    ->default('manual')
                    ->after('journal_entry_id');
            }

            if (!Schema::hasColumn('completed_works', 'planning_status')) {
                $table->string('planning_status', 32)
                    ->default('planned')
                    ->after('work_origin_type');
            }

            $table->index(['project_id', 'planning_status'], 'completed_works_project_planning_status_idx');
            $table->index(['journal_entry_id', 'work_origin_type'], 'completed_works_journal_origin_idx');
            $table->index(['estimate_item_id', 'planning_status'], 'completed_works_estimate_planning_idx');
        });
    }

    public function down(): void
    {
        Schema::table('completed_works', function (Blueprint $table): void {
            $table->dropIndex('completed_works_project_planning_status_idx');
            $table->dropIndex('completed_works_journal_origin_idx');
            $table->dropIndex('completed_works_estimate_planning_idx');

            if (Schema::hasColumn('completed_works', 'planning_status')) {
                $table->dropColumn('planning_status');
            }

            if (Schema::hasColumn('completed_works', 'work_origin_type')) {
                $table->dropColumn('work_origin_type');
            }

            if (Schema::hasColumn('completed_works', 'journal_entry_id')) {
                $table->dropConstrainedForeignId('journal_entry_id');
            }

            if (Schema::hasColumn('completed_works', 'estimate_item_id')) {
                $table->dropConstrainedForeignId('estimate_item_id');
            }
        });
    }
};
