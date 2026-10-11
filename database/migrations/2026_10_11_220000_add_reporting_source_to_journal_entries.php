<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('construction_journal_entries', function (Blueprint $table): void {
            $table->foreignId('source_entry_id')->nullable()->constrained('construction_journal_entries')->restrictOnDelete();
            $table->jsonb('source_entry_snapshot')->nullable();
        });
        DB::statement('CREATE UNIQUE INDEX journal_entries_reporting_source_unique ON construction_journal_entries (journal_id, source_entry_id) WHERE deleted_at IS NULL AND source_entry_id IS NOT NULL');
    }

    public function down(): void
    {
        if (DB::table('construction_journal_entries')->whereNotNull('source_entry_id')->exists()) {
            throw new RuntimeException('journal_reporting_sources_must_be_preserved');
        }
        DB::statement('DROP INDEX IF EXISTS journal_entries_reporting_source_unique');
        Schema::table('construction_journal_entries', function (Blueprint $table): void {
            $table->dropForeign(['source_entry_id']);
            $table->dropColumn(['source_entry_id', 'source_entry_snapshot']);
        });
    }
};
