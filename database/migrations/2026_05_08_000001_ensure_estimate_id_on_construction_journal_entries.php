<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('construction_journal_entries') || Schema::hasColumn('construction_journal_entries', 'estimate_id')) {
            return;
        }

        Schema::table('construction_journal_entries', function (Blueprint $table): void {
            $table->foreignId('estimate_id')
                ->nullable()
                ->after('schedule_task_id')
                ->constrained('estimates')
                ->nullOnDelete();

            $table->index('estimate_id');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('construction_journal_entries') || !Schema::hasColumn('construction_journal_entries', 'estimate_id')) {
            return;
        }

        Schema::table('construction_journal_entries', function (Blueprint $table): void {
            $table->dropForeign(['estimate_id']);
            $table->dropIndex(['estimate_id']);
            $table->dropColumn('estimate_id');
        });
    }
};
