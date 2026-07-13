<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Support;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class TrainingBenchmarkOnlineMigrationRuntime
{
    private static int $observedCheckpointCount = 0;

    public static function resetObservedCheckpoints(): void
    {
        self::$observedCheckpointCount = 0;
    }

    public static function observedCheckpointCount(): int
    {
        return self::$observedCheckpointCount;
    }

    public function checkpoint(string $name): void
    {
        self::$observedCheckpointCount++;
        $interruptOrdinal = getenv('ESTIMATE_CONTRACT_INTERRUPT_ORDINAL');
        if ($interruptOrdinal === false || $interruptOrdinal === '' || ctype_digit($interruptOrdinal) === false || (int) $interruptOrdinal !== self::$observedCheckpointCount) {
            return;
        }
        $database = (string) DB::connection()->getDatabaseName();
        if (getenv('RUN_POSTGRES_TRAINING_BENCHMARK_CONTRACT') !== '1' || ! str_ends_with($database, '_contract')) {
            throw new RuntimeException('estimate_generation_online_migration_fault_injection_forbidden');
        }
        throw new RuntimeException('estimate_generation_online_migration_interrupted_ordinal:'.self::$observedCheckpointCount.':'.$name);
    }

    /** @return array{lock_timeout: string, statement_timeout: string} */
    public function configureSessionTimeouts(): array
    {
        $previous = (array) DB::selectOne("SELECT current_setting('lock_timeout') AS lock_timeout, current_setting('statement_timeout') AS statement_timeout");
        try {
            DB::statement("SET lock_timeout = '5s'");
            if (getenv('ESTIMATE_CONTRACT_FAIL_SECOND_TIMEOUT_SET') === '1') {
                $database = (string) DB::connection()->getDatabaseName();
                if (getenv('RUN_POSTGRES_TRAINING_BENCHMARK_CONTRACT') !== '1' || ! str_ends_with($database, '_contract')) {
                    throw new RuntimeException('estimate_generation_online_migration_fault_injection_forbidden');
                }
                throw new RuntimeException('estimate_generation_online_migration_second_timeout_set_failed');
            }
            DB::statement("SET statement_timeout = '15min'");
        } catch (\Throwable $exception) {
            $this->restoreSessionTimeouts(['lock_timeout' => (string) $previous['lock_timeout'], 'statement_timeout' => (string) $previous['statement_timeout']]);
            throw $exception;
        }

        return ['lock_timeout' => (string) $previous['lock_timeout'], 'statement_timeout' => (string) $previous['statement_timeout']];
    }

    /** @param array{lock_timeout: string, statement_timeout: string} $settings */
    public function restoreSessionTimeouts(array $settings): void
    {
        DB::select("SELECT set_config('lock_timeout', ?, false), set_config('statement_timeout', ?, false)", [$settings['lock_timeout'], $settings['statement_timeout']]);
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

    public function ensureConcurrentIndex(string $name, string $createSql): void
    {
        $this->assertIdentifier($name);
        if (preg_match('/^CREATE (UNIQUE )?INDEX CONCURRENTLY '.preg_quote($name, '/').' ON (?:(?<schema>[a-z][a-z0-9_]*)\.)?(?<table>[a-z][a-z0-9_]*) (?<definition>.+)$/D', $createSql, $matches) !== 1) {
            throw new InvalidArgumentException('estimate_generation_online_migration_index_sql_invalid');
        }
        $expectedSchema = $matches['schema'] !== '' ? $matches['schema'] : 'public';

        $catalog = DB::selectOne(
            "SELECT i.indisvalid, i.indisready, i.indisunique, ns.nspname AS schema_name, tbl.relname AS table_name,
                    ARRAY(SELECT a.attname FROM unnest(i.indkey) WITH ORDINALITY AS keys(attnum, ord) JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = keys.attnum ORDER BY keys.ord) AS columns,
                    COALESCE(pg_get_expr(i.indpred, i.indrelid), '') AS predicate
             FROM pg_class c JOIN pg_namespace ns ON ns.oid = c.relnamespace JOIN pg_index i ON i.indexrelid = c.oid JOIN pg_class tbl ON tbl.oid = i.indrelid
             WHERE c.relname = ? AND ns.nspname = ?",
            [$name, $expectedSchema],
        );
        if ($catalog !== null && (bool) $catalog->indisvalid && (bool) $catalog->indisready) {
            $probe = substr($name, 0, 47).'_definition_probe';
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.$expectedSchema.'.'.$probe);
            $this->checkpoint('index.'.$expectedSchema.'.'.$name.'.probe_drop');
            $probeSql = preg_replace('/ INDEX CONCURRENTLY '.preg_quote($name, '/').' /', ' INDEX CONCURRENTLY '.$probe.' ', $createSql, 1);
            DB::statement((string) $probeSql);
            $this->checkpoint('index.'.$expectedSchema.'.'.$name.'.probe_create');
            $definitions = DB::select('SELECT c.relname, pg_get_indexdef(c.oid) AS definition FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = ? AND c.relname IN (?, ?)', [$expectedSchema, $name, $probe]);
            $byName = [];
            foreach ($definitions as $row) {
                $byName[$row->relname] = preg_replace('/INDEX (?:'.preg_quote($name, '/').'|'.preg_quote($probe, '/').') /', 'INDEX <name> ', (string) $row->definition, 1);
            }
            DB::statement('DROP INDEX CONCURRENTLY '.$expectedSchema.'.'.$probe);
            $this->checkpoint('index.'.$expectedSchema.'.'.$name.'.probe_compare_drop');
            if (($byName[$name] ?? null) !== ($byName[$probe] ?? null)) {
                throw new RuntimeException('estimate_generation_online_migration_index_definition_mismatch');
            }

            return;
        }
        if ($catalog !== null) {
            DB::statement('DROP INDEX CONCURRENTLY '.$expectedSchema.'.'.$name);
            $this->checkpoint('index.'.$expectedSchema.'.'.$name.'.invalid_drop');
        }
        DB::statement($createSql);
        $this->checkpoint('index.'.$expectedSchema.'.'.$name.'.create');

        $valid = DB::selectOne(
            'SELECT i.indisvalid, i.indisready FROM pg_class c JOIN pg_namespace ns ON ns.oid = c.relnamespace JOIN pg_index i ON i.indexrelid = c.oid WHERE c.relname = ? AND ns.nspname = ?',
            [$name, $expectedSchema],
        );
        if ($valid === null || ! (bool) $valid->indisvalid || ! (bool) $valid->indisready) {
            throw new RuntimeException('estimate_generation_online_migration_index_invalid');
        }
        $this->checkpoint('index.'.$expectedSchema.'.'.$name.'.catalog_valid');
    }

    public function ensureConstraint(string $table, string $name, string $definition, string $schema = 'public'): void
    {
        $this->assertIdentifier($table);
        $this->assertIdentifier($name);
        $this->assertIdentifier($schema);
        $qualified = $schema.'.'.$table;
        $existing = DB::selectOne('SELECT pg_get_constraintdef(c.oid, true) AS definition, c.convalidated FROM pg_constraint c JOIN pg_class t ON t.oid = c.conrelid JOIN pg_namespace n ON n.oid = t.relnamespace WHERE n.nspname = ? AND t.relname = ? AND c.conname = ?', [$schema, $table, $name]);
        if ($existing === null) {
            DB::statement("ALTER TABLE {$qualified} ADD CONSTRAINT {$name} {$definition} NOT VALID");
            $this->checkpoint('constraint.'.$schema.'.'.$table.'.'.$name.'.add_not_valid');

            return;
        }
        $probe = substr($name, 0, 48).'_definition_probe';
        DB::transaction(function () use ($schema, $table, $qualified, $name, $probe, $definition, $existing): void {
            DB::statement("ALTER TABLE {$qualified} DROP CONSTRAINT IF EXISTS {$probe}");
            $this->checkpoint('constraint.'.$schema.'.'.$table.'.'.$name.'.probe_drop');
            DB::statement("ALTER TABLE {$qualified} ADD CONSTRAINT {$probe} {$definition} NOT VALID");
            $this->checkpoint('constraint.'.$schema.'.'.$table.'.'.$name.'.probe_add');
            $canonical = DB::selectOne('SELECT pg_get_constraintdef(c.oid, true) AS definition FROM pg_constraint c JOIN pg_class t ON t.oid = c.conrelid JOIN pg_namespace n ON n.oid = t.relnamespace WHERE n.nspname = ? AND t.relname = ? AND c.conname = ?', [$schema, $table, $probe]);
            if ($canonical === null || $this->normalizeDefinition((string) $existing->definition) !== $this->normalizeDefinition((string) $canonical->definition)) {
                throw new RuntimeException('estimate_generation_online_migration_constraint_definition_mismatch');
            }
            DB::statement("ALTER TABLE {$qualified} DROP CONSTRAINT {$probe}");
            $this->checkpoint('constraint.'.$schema.'.'.$table.'.'.$name.'.probe_compare_drop');
        });
    }

    public function validateConstraint(string $table, string $name, string $schema = 'public'): void
    {
        $this->assertIdentifier($table);
        $this->assertIdentifier($name);
        $this->assertIdentifier($schema);
        $validated = DB::selectOne('SELECT c.convalidated FROM pg_constraint c JOIN pg_class t ON t.oid = c.conrelid JOIN pg_namespace n ON n.oid = t.relnamespace WHERE n.nspname = ? AND t.relname = ? AND c.conname = ?', [$schema, $table, $name]);
        if ($validated === null) {
            throw new RuntimeException('estimate_generation_online_migration_constraint_missing');
        }
        if (! (bool) $validated->convalidated) {
            DB::statement("ALTER TABLE {$schema}.{$table} VALIDATE CONSTRAINT {$name}");
            $this->checkpoint('constraint.'.$schema.'.'.$table.'.'.$name.'.validate');
        }
    }

    public function swapValidatedConstraint(string $table, string $finalName, string $temporaryName, string $definition, string $schema = 'public'): void
    {
        $this->ensureConstraint($table, $temporaryName, $definition, $schema);
        $this->validateConstraint($table, $temporaryName, $schema);
        DB::transaction(function () use ($schema, $table, $finalName, $temporaryName): void {
            DB::statement("LOCK TABLE {$schema}.{$table} IN ACCESS EXCLUSIVE MODE");
            $this->checkpoint('constraint.'.$schema.'.'.$table.'.'.$finalName.'.swap_lock');
            DB::statement("ALTER TABLE {$schema}.{$table} DROP CONSTRAINT IF EXISTS {$finalName}");
            $this->checkpoint('constraint.'.$schema.'.'.$table.'.'.$finalName.'.swap_drop');
            DB::statement("ALTER TABLE {$schema}.{$table} RENAME CONSTRAINT {$temporaryName} TO {$finalName}");
            $this->checkpoint('constraint.'.$schema.'.'.$table.'.'.$finalName.'.swap_rename');
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
