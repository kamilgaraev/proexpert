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
        Schema::table('construction_journal_entries', function (Blueprint $table) {
            $table->foreignId('estimate_id')
                ->nullable()
                ->after('schedule_task_id')
                ->constrained('estimates')
                ->nullOnDelete()
                ->comment('Связанная смета');
            
            $table->index('estimate_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('construction_journal_entries', function (Blueprint $table) {
            $table->dropForeign(['estimate_id']);
            $table->dropIndex(['estimate_id']);
            $table->dropColumn('estimate_id');
        });
    }
};

