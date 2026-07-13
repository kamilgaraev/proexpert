<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Support;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class TrainingBenchmarkOnlineMigrationRuntime
{
    public function checkpoint(string $name): void
    {
        if (getenv('ESTIMATE_CONTRACT_INTERRUPT_AFTER') !== $name) {
            return;
        }
        $database = (string) DB::connection()->getDatabaseName();
        if (getenv('RUN_POSTGRES_TRAINING_BENCHMARK_CONTRACT') !== '1' || ! str_ends_with($database, '_contract')) {
            throw new RuntimeException('estimate_generation_online_migration_fault_injection_forbidden');
        }
        throw new RuntimeException('estimate_generation_online_migration_interrupted:'.$name);
    }

    /** @return array{lock_timeout: string, statement_timeout: string} */
    public function configureSessionTimeouts(): array
    {
        $previous = (array) DB::selectOne("SELECT current_setting('lock_timeout') AS lock_timeout, current_setting('statement_timeout') AS statement_timeout");
        DB::statement("SET lock_timeout = '5s'");
        DB::statement("SET statement_timeout = '15min'");

        return ['lock_timeout' => (string) $previous['lock_timeout'], 'statement_timeout' => (string) $previous['statement_timeout']];
    }

    /** @param array{lock_timeout: string, statement_timeout: string} $settings */
    public function restoreSessionTimeouts(array $settings): void
    {
        DB::select("SELECT set_config('lock_timeout', ?, false), set_config('statement_timeout', ?, false)", [$settings['lock_timeout'], $settings['statement_timeout']]);
    }

    public function runIdempotentPhase(string $phase, callable $operation): void
    {
        if (preg_match('/^[a-z0-9_]{3,100}$/D', $phase) !== 1) {
            throw new InvalidArgumentException('estimate_generation_online_migration_phase_invalid');
        }
        DB::statement('CREATE TABLE IF NOT EXISTS estimate_generation_online_migration_phases (phase text PRIMARY KEY, completed_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP)');
        if (DB::table('estimate_generation_online_migration_phases')->where('phase', $phase)->exists()) {
            return;
        }
        DB::transaction(function () use ($phase, $operation): void {
            if (DB::table('estimate_generation_online_migration_phases')->where('phase', $phase)->lockForUpdate()->exists()) {
                return;
            }
            $operation();
            DB::table('estimate_generation_online_migration_phases')->insert(['phase' => $phase]);
        });
    }

    public function backfillDatasets(?callable $interrupt = null): void
    {
        $highWater = (int) DB::table('estimate_generation_training_datasets')->max('id');
        $this->runPhase(static fn (int $cursor): array => DB::table('estimate_generation_training_datasets')->whereNull('dataset_key')->where('id', '>', $cursor)->where('id', '<=', $highWater)->orderBy('id')->limit(500)->pluck('id')->all(), static function (array $ids): void {
            DB::statement("UPDATE estimate_generation_training_datasets SET dataset_key = uuid, version = 1, dataset_type = 'development', scope = 'organization', status = CASE WHEN status = 'processing' THEN 'processing' WHEN status IN ('processed', 'failed') THEN 'review_required' ELSE 'draft' END WHERE id = ANY(?::bigint[])", ['{'.implode(',', $ids).'}']);
        }, $interrupt);
    }

    public function backfillExamples(?callable $interrupt = null): void
    {
        $highWater = (int) DB::table('estimate_generation_training_examples')->max('id');
        $this->runPhase(static fn (int $cursor): array => DB::table('estimate_generation_training_examples')->whereIn('status', ['accepted', 'indexed'])->where(static fn ($query) => $query->whereNull('reviewed_by')->orWhereNull('reviewed_at'))->where('id', '>', $cursor)->where('id', '<=', $highWater)->orderBy('id')->limit(500)->pluck('id')->all(), static fn (array $ids) => DB::table('estimate_generation_training_examples')->whereIn('id', $ids)->update(['status' => 'pending', 'accepted_at' => null, 'indexed_at' => null]), $interrupt);
    }

    public function backfillMembership(?callable $interrupt = null): void
    {
        $highWater = (int) DB::table('estimate_generation_training_examples')->max('id');
        $this->runPhase(static fn (int $cursor): array => DB::table('estimate_generation_training_examples')->where(static fn ($query) => $query->whereNull('organization_id')->orWhereNull('dataset_version'))->where('id', '>', $cursor)->where('id', '<=', $highWater)->orderBy('id')->limit(500)->pluck('id')->all(), static function (array $ids): void {
            DB::statement('UPDATE estimate_generation_training_examples e SET organization_id = d.organization_id, dataset_version = d.version FROM estimate_generation_training_datasets d WHERE e.id = ANY(?::bigint[]) AND d.id = e.training_dataset_id', ['{'.implode(',', $ids).'}']);
        }, $interrupt);
    }

    public function backfillProcessingLeases(?callable $interrupt = null): void
    {
        $highWater = (int) DB::table('estimate_generation_training_datasets')->max('id');
        $this->runPhase(static fn (int $cursor): array => DB::table('estimate_generation_training_datasets')->where('status', 'processing')->where('id', '>', $cursor)->where('id', '<=', $highWater)->orderBy('id')->limit(500)->pluck('id')->all(), static fn (array $ids) => DB::table('estimate_generation_training_datasets')->whereIn('id', $ids)->update(['status' => 'draft', 'processing_token' => null, 'processing_lease_expires_at' => null, 'error_message' => 'training_dataset_processing_lease_expired']), $interrupt);
    }

    public function backfill(
        string $table,
        string $pendingColumn,
        int $batchSize,
        callable $apply,
        ?callable $interruptAfterBatch = null,
    ): void {
        $this->assertIdentifier($table);
        $this->assertIdentifier($pendingColumn);
        if ($batchSize < 1 || $batchSize > 10_000) {
            throw new InvalidArgumentException('estimate_generation_online_migration_batch_invalid');
        }

        $batch = 0;
        while (true) {
            $ids = DB::table($table)->where($pendingColumn, false)->orderBy('id')->limit($batchSize)->pluck('id')->all();
            if ($ids === []) {
                return;
            }
            $apply($ids);
            $batch++;
            if ($interruptAfterBatch !== null && $interruptAfterBatch($batch)) {
                throw new RuntimeException('estimate_generation_online_migration_interrupted');
            }
        }
    }

    public function ensureConcurrentIndex(string $name, string $createSql): void
    {
        $this->assertIdentifier($name);
        if (preg_match('/^CREATE (UNIQUE )?INDEX CONCURRENTLY '.preg_quote($name, '/').' ON (?:(?<schema>[a-z][a-z0-9_]*)\.)?(?<table>[a-z][a-z0-9_]*) \((?<columns>[a-z0-9_, ]+)\)(?: WHERE (?<predicate>.+))?$/D', $createSql, $matches) !== 1) {
            throw new InvalidArgumentException('estimate_generation_online_migration_index_sql_invalid');
        }
        $expectedSchema = $matches['schema'] !== '' ? $matches['schema'] : 'public';
        $expectedColumns = array_map('trim', explode(',', $matches['columns']));

        $catalog = DB::selectOne(
            "SELECT i.indisvalid, i.indisready, i.indisunique, ns.nspname AS schema_name, tbl.relname AS table_name,
                    ARRAY(SELECT a.attname FROM unnest(i.indkey) WITH ORDINALITY AS keys(attnum, ord) JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = keys.attnum ORDER BY keys.ord) AS columns,
                    COALESCE(pg_get_expr(i.indpred, i.indrelid), '') AS predicate
             FROM pg_class c JOIN pg_namespace ns ON ns.oid = c.relnamespace JOIN pg_index i ON i.indexrelid = c.oid JOIN pg_class tbl ON tbl.oid = i.indrelid
             WHERE c.relname = ? AND ns.nspname = ?",
            [$name, $expectedSchema],
        );
        if ($catalog !== null && (bool) $catalog->indisvalid && (bool) $catalog->indisready) {
            $actualColumns = trim((string) $catalog->columns, '{}') === '' ? [] : str_getcsv(trim((string) $catalog->columns, '{}'));
            $expectedUnique = $matches[1] !== '';
            $expectedPredicate = trim($matches['predicate'] ?? '');
            if ((string) $catalog->schema_name !== $expectedSchema || (string) $catalog->table_name !== $matches['table']
                || (bool) $catalog->indisunique !== $expectedUnique || $actualColumns !== $expectedColumns
                || preg_replace('/\s+/', ' ', trim((string) $catalog->predicate)) !== preg_replace('/\s+/', ' ', $expectedPredicate)) {
                throw new RuntimeException('estimate_generation_online_migration_index_definition_mismatch');
            }

            return;
        }
        if ($catalog !== null) {
            DB::statement('DROP INDEX CONCURRENTLY '.$name);
        }
        DB::statement($createSql);

        $valid = DB::selectOne(
            'SELECT i.indisvalid, i.indisready FROM pg_class c JOIN pg_namespace ns ON ns.oid = c.relnamespace JOIN pg_index i ON i.indexrelid = c.oid WHERE c.relname = ? AND ns.nspname = ?',
            [$name, $expectedSchema],
        );
        if ($valid === null || ! (bool) $valid->indisvalid || ! (bool) $valid->indisready) {
            throw new RuntimeException('estimate_generation_online_migration_index_invalid');
        }
    }

    public function ensureConstraint(string $table, string $name, string $definition): void
    {
        $this->assertIdentifier($table);
        $this->assertIdentifier($name);
        $existing = DB::selectOne('SELECT pg_get_constraintdef(c.oid, true) AS definition, c.convalidated FROM pg_constraint c JOIN pg_class t ON t.oid = c.conrelid WHERE t.relname = ? AND c.conname = ?', [$table, $name]);
        if ($existing === null) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} {$definition} NOT VALID");

            return;
        }
        $probe = substr($name, 0, 48).'_definition_probe';
        DB::transaction(function () use ($table, $probe, $definition, $existing): void {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$probe}");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$probe} {$definition} NOT VALID");
            $canonical = DB::selectOne('SELECT pg_get_constraintdef(c.oid, true) AS definition FROM pg_constraint c JOIN pg_class t ON t.oid = c.conrelid WHERE t.relname = ? AND c.conname = ?', [$table, $probe]);
            if ($canonical === null || $this->normalizeDefinition((string) $existing->definition) !== $this->normalizeDefinition((string) $canonical->definition)) {
                throw new RuntimeException('estimate_generation_online_migration_constraint_definition_mismatch');
            }
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$probe}");
        });
    }

    public function validateConstraint(string $table, string $name): void
    {
        $this->assertIdentifier($table);
        $this->assertIdentifier($name);
        $validated = DB::selectOne('SELECT c.convalidated FROM pg_constraint c JOIN pg_class t ON t.oid = c.conrelid WHERE t.relname = ? AND c.conname = ?', [$table, $name]);
        if ($validated === null) {
            throw new RuntimeException('estimate_generation_online_migration_constraint_missing');
        }
        if (! (bool) $validated->convalidated) {
            DB::statement("ALTER TABLE {$table} VALIDATE CONSTRAINT {$name}");
        }
    }

    public function swapValidatedConstraint(string $table, string $finalName, string $temporaryName, string $definition): void
    {
        $this->ensureConstraint($table, $temporaryName, $definition);
        $this->validateConstraint($table, $temporaryName);
        DB::transaction(function () use ($table, $finalName, $temporaryName): void {
            DB::statement("LOCK TABLE {$table} IN ACCESS EXCLUSIVE MODE");
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$finalName}");
            DB::statement("ALTER TABLE {$table} RENAME CONSTRAINT {$temporaryName} TO {$finalName}");
        });
    }

    private function assertIdentifier(string $identifier): void
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $identifier) !== 1) {
            throw new InvalidArgumentException('estimate_generation_online_migration_identifier_invalid');
        }
    }

    private function normalizeDefinition(string $definition): string
    {
        return preg_replace('/ not valid$/', '', strtolower((string) preg_replace('/\s+/', ' ', trim($definition)))) ?? '';
    }

    private function runPhase(callable $pendingIds, callable $apply, ?callable $interrupt): void
    {
        $batch = 0;
        $cursor = 0;
        while (($ids = $pendingIds($cursor)) !== []) {
            $apply($ids);
            $cursor = (int) max($ids);
            $batch++;
            if ($interrupt !== null && $interrupt($batch)) {
                throw new RuntimeException('estimate_generation_online_migration_interrupted');
            }
        }
    }
}
