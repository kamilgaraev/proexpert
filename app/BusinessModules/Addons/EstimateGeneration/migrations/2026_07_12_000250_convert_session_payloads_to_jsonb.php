<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public $withinTransaction = false;

    private const COLUMNS = ['input_payload', 'analysis_payload', 'draft_payload', 'problem_flags'];
    private const TRIGGER = 'eg_session_payload_dual_write_v1';
    private const FUNCTION = 'eg_session_payload_dual_write_v1';

    public function up(): void
    {
        $this->convert('json', 'jsonb');
    }

    public function down(): void
    {
        $this->convert('jsonb', 'json');
    }

    private function convert(string $sourceType, string $targetType): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        $types = $this->columnTypes();
        $canonicalTarget = $this->allColumnsHaveType($types, self::COLUMNS, $targetType);
        $canonicalSource = $this->allColumnsHaveType($types, self::COLUMNS, $sourceType);
        $shadows = array_map(static fn (string $column): string => "{$column}__{$targetType}_shadow", self::COLUMNS);
        $old = array_map(static fn (string $column): string => "{$column}__rollout_old", self::COLUMNS);
        $shadowCount = count(array_intersect($shadows, array_keys($types)));
        $oldCount = count(array_intersect($old, array_keys($types)));
        if ($canonicalTarget && $shadowCount === 0 && $oldCount === 0) {
            return;
        }
        if ($canonicalTarget && $shadowCount === 0 && $oldCount === count(self::COLUMNS)
            && $this->allColumnsHaveType($types, $old, $sourceType)) {
            $this->cleanupOldColumns();

            return;
        }
        if (! $canonicalSource || $oldCount !== 0 || ! in_array($shadowCount, [0, count(self::COLUMNS)], true)) {
            throw new RuntimeException('estimate_generation.payload_rollout_ambiguous');
        }
        if ($shadowCount === 0) {
            DB::transaction(function () use ($targetType): void {
                DB::statement("SET LOCAL lock_timeout = '2s'");
                $definitions = implode(', ', array_map(static fn (string $column): string => "ADD COLUMN {$column}__{$targetType}_shadow {$targetType}", self::COLUMNS));
                DB::statement("ALTER TABLE estimate_generation_sessions {$definitions}");
                $this->installDualWriteTrigger($targetType);
            });
        } else {
            $this->installDualWriteTrigger($targetType);
        }
        foreach (self::COLUMNS as $column) {
            $shadow = "{$column}__{$targetType}_shadow";
            do {
                $updated = DB::transaction(function () use ($column, $shadow, $targetType): int {
                    DB::statement("SET LOCAL statement_timeout = '5s'");
                    DB::statement("SET LOCAL lock_timeout = '2s'");

                    return DB::affectingStatement(<<<SQL
WITH batch AS (
    SELECT id FROM estimate_generation_sessions
    WHERE {$shadow} IS NULL AND {$column} IS NOT NULL
    ORDER BY id LIMIT 1000 FOR UPDATE SKIP LOCKED
)
UPDATE estimate_generation_sessions AS sessions
SET {$shadow} = sessions.{$column}::{$targetType}
FROM batch WHERE sessions.id = batch.id
SQL);
                });
            } while ($updated > 0);
        }
        DB::transaction(function () use ($targetType): void {
            DB::statement("SET LOCAL statement_timeout = '10s'");
            DB::statement("SET LOCAL lock_timeout = '2s'");
            DB::statement('LOCK TABLE estimate_generation_sessions IN ACCESS EXCLUSIVE MODE');
            $assignments = implode(', ', array_map(static fn (string $column): string => "{$column}__{$targetType}_shadow = {$column}::{$targetType}", self::COLUMNS));
            $mismatch = implode(' OR ', array_map(static fn (string $column): string => "{$column}__{$targetType}_shadow::jsonb IS DISTINCT FROM {$column}::jsonb", self::COLUMNS));
            DB::affectingStatement("UPDATE estimate_generation_sessions SET {$assignments} WHERE {$mismatch}");
            if (DB::table('estimate_generation_sessions')->whereRaw($mismatch)->exists()) {
                throw new RuntimeException('estimate_generation.payload_rollout_reconciliation_failed');
            }
            DB::statement('DROP TRIGGER IF EXISTS '.self::TRIGGER.' ON estimate_generation_sessions');
            DB::statement('DROP FUNCTION IF EXISTS '.self::FUNCTION.'()');
            foreach (self::COLUMNS as $column) {
                DB::statement("ALTER TABLE estimate_generation_sessions RENAME COLUMN {$column} TO {$column}__rollout_old");
                DB::statement("ALTER TABLE estimate_generation_sessions RENAME COLUMN {$column}__{$targetType}_shadow TO {$column}");
            }
            DB::statement('ALTER TABLE estimate_generation_sessions ALTER COLUMN input_payload SET NOT NULL');
        });
        $this->cleanupOldColumns();
    }

    private function installDualWriteTrigger(string $targetType): void
    {
        $assignments = implode("\n", array_map(static fn (string $column): string => "NEW.{$column}__{$targetType}_shadow := NEW.{$column}::{$targetType};", self::COLUMNS));
        DB::unprepared('DROP TRIGGER IF EXISTS '.self::TRIGGER.' ON estimate_generation_sessions; DROP FUNCTION IF EXISTS '.self::FUNCTION."(); CREATE FUNCTION ".self::FUNCTION."() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN {$assignments} RETURN NEW; END; \$\$; CREATE TRIGGER ".self::TRIGGER.' BEFORE INSERT OR UPDATE ON estimate_generation_sessions FOR EACH ROW EXECUTE FUNCTION '.self::FUNCTION.'();');
    }

    private function cleanupOldColumns(): void
    {
        DB::transaction(function (): void {
            DB::statement("SET LOCAL statement_timeout = '10s'");
            DB::statement("SET LOCAL lock_timeout = '2s'");
            DB::statement('ALTER TABLE estimate_generation_sessions DROP COLUMN input_payload__rollout_old, DROP COLUMN analysis_payload__rollout_old, DROP COLUMN draft_payload__rollout_old, DROP COLUMN problem_flags__rollout_old');
        });
    }

    /** @return array<string, string> */
    private function columnTypes(): array
    {
        return collect(DB::select("SELECT column_name, data_type FROM information_schema.columns WHERE table_schema='public' AND table_name='estimate_generation_sessions'"))->mapWithKeys(static fn (object $column): array => [(string) $column->column_name => (string) $column->data_type])->all();
    }

    /** @param array<string, string> $types @param list<string> $columns */
    private function allColumnsHaveType(array $types, array $columns, string $type): bool
    {
        foreach ($columns as $column) {
            if (($types[$column] ?? null) !== $type) {
                return false;
            }
        }

        return true;
    }
};
