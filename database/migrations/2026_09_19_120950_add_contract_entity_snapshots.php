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
        foreach (['contract_builder_revisions', 'contract_builder_drafts'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->jsonb('entity_snapshots')->default('{}');
            });
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_entity_snapshots_object CHECK (jsonb_typeof(entity_snapshots) = 'object')");
        }
    }

    public function down(): void
    {
        foreach (['contract_builder_drafts', 'contract_builder_revisions'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn('entity_snapshots');
            });
        }
    }
};
