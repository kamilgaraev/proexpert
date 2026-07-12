<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public $withinTransaction = false;

    private const COLUMNS = ['input_payload', 'analysis_payload', 'draft_payload', 'problem_flags'];

    public function up(): void
    {
        $this->convert('jsonb');
    }

    public function down(): void
    {
        $this->convert('json');
    }

    private function convert(string $targetType): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement("SET statement_timeout = '5s'");
        DB::statement("SET lock_timeout = '2s'");
        foreach (self::COLUMNS as $column) {
            $shadow = "{$column}__{$targetType}_shadow";
            if (! Schema::hasColumn('estimate_generation_sessions', $shadow)) {
                DB::statement("ALTER TABLE estimate_generation_sessions ADD COLUMN {$shadow} {$targetType}");
            }
            do {
                $updated = DB::affectingStatement(<<<SQL
WITH batch AS (
    SELECT id FROM estimate_generation_sessions
    WHERE {$shadow} IS NULL AND {$column} IS NOT NULL
    ORDER BY id LIMIT 1000 FOR UPDATE SKIP LOCKED
)
UPDATE estimate_generation_sessions AS sessions
SET {$shadow} = sessions.{$column}::{$targetType}
FROM batch WHERE sessions.id = batch.id
SQL);
            } while ($updated > 0);
            $remaining = DB::table('estimate_generation_sessions')->whereNotNull($column)->whereNull($shadow)->exists();
            if ($remaining) {
                throw new RuntimeException("estimate_generation.session_payload_backfill_incomplete:{$column}");
            }
        }
        DB::transaction(function () use ($targetType): void {
            DB::statement('SET LOCAL lock_timeout = \'2s\'');
            DB::statement('LOCK TABLE estimate_generation_sessions IN ACCESS EXCLUSIVE MODE');
            foreach (self::COLUMNS as $column) {
                $shadow = "{$column}__{$targetType}_shadow";
                $old = "{$column}__rollout_old";
                DB::statement("ALTER TABLE estimate_generation_sessions RENAME COLUMN {$column} TO {$old}");
                DB::statement("ALTER TABLE estimate_generation_sessions RENAME COLUMN {$shadow} TO {$column}");
            }
            DB::statement('ALTER TABLE estimate_generation_sessions ALTER COLUMN input_payload SET NOT NULL');
        });
        DB::statement('ALTER TABLE estimate_generation_sessions DROP COLUMN input_payload__rollout_old, DROP COLUMN analysis_payload__rollout_old, DROP COLUMN draft_payload__rollout_old, DROP COLUMN problem_flags__rollout_old');
    }
};
