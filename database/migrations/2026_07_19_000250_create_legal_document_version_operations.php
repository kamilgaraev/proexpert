<?php

declare(strict_types=1);

use App\Services\LegalArchive\Files\Schema\LegalDocumentVersionOperationPostgresSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        $table = LegalDocumentVersionOperationPostgresSchema::TABLE;
        $relation = $this->relationDescriptor($table);
        if ($relation !== null && ($relation->relation_kind !== 'r' || $relation->persistence !== 'p')) {
            throw new RuntimeException('legal_archive_version_operation_relation_descriptor_mismatch');
        }
        if ($relation === null) {
            DB::statement("CREATE TABLE {$table} ()");
            $relation = $this->relationDescriptor($table);
        }
        if ($relation === null || $relation->relation_kind !== 'r' || $relation->persistence !== 'p') {
            throw new RuntimeException('legal_archive_version_operation_relation_descriptor_mismatch');
        }

        foreach (LegalDocumentVersionOperationPostgresSchema::columns() as $name => $expected) {
            $actual = $this->columnDescriptor($table, $name);
            if ($actual === null) {
                DB::statement("ALTER TABLE {$table} ADD COLUMN {$name} {$expected['sql']}");
                $actual = $this->columnDescriptor($table, $name);
            }
            if ($actual === null || ! $this->sameColumnDescriptor($actual, $expected)) {
                throw new RuntimeException("legal_archive_version_operation_column_descriptor_mismatch:{$name}");
            }
        }
        $this->assertExactColumnSet($table);
        $this->assertIdSequenceDescriptor($table);

        foreach (LegalDocumentVersionOperationPostgresSchema::indexes() as $name => $expected) {
            $actual = $this->indexDescriptor($name);
            if ($actual === null) {
                DB::statement($expected['sql']);
                $actual = $this->indexDescriptor($name);
            }
            if ($actual === null || ! $this->sameIndexDescriptor($actual, $table, $expected)) {
                throw new RuntimeException("legal_archive_version_operation_index_descriptor_mismatch:{$name}");
            }
        }
        $this->assertExactIndexSet($table);
        $this->assertExactConstraintSet($table);
    }

    public function down(): void
    {
        throw new RuntimeException('legal_archive_version_operation_migrations_are_forward_only');
    }

    private function relationDescriptor(string $table): ?object
    {
        return DB::selectOne(
            'SELECT c.relkind AS relation_kind, c.relpersistence AS persistence FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = current_schema() AND c.relname = ?',
            [$table],
        );
    }

    private function columnDescriptor(string $table, string $column): ?object
    {
        return DB::selectOne(
            <<<'SQL'
SELECT format_type(a.atttypid, a.atttypmod) AS formatted_type,
       a.attnotnull::integer AS not_null,
       pg_get_expr(d.adbin, d.adrelid) AS default_definition
FROM pg_attribute a
JOIN pg_class c ON c.oid = a.attrelid
JOIN pg_namespace n ON n.oid = c.relnamespace
LEFT JOIN pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum
WHERE n.nspname = current_schema() AND c.relname = ? AND a.attname = ?
  AND a.attnum > 0 AND NOT a.attisdropped
SQL,
            [$table, $column],
        );
    }

    /** @param array{type: string, nullable: bool, default: ?string, sql: string} $expected */
    private function sameColumnDescriptor(object $actual, array $expected): bool
    {
        $default = $actual->default_definition === null ? null : strtolower(trim((string) $actual->default_definition));

        return strtolower((string) $actual->formatted_type) === $expected['type']
            && (bool) $actual->not_null === ! $expected['nullable']
            && $default === $expected['default'];
    }

    private function indexDescriptor(string $name): ?object
    {
        return DB::selectOne(
            <<<'SQL'
SELECT t.relname AS table_name, am.amname AS access_method,
       i.indisunique::integer AS indisunique, i.indisprimary::integer AS indisprimary,
       i.indisvalid::integer AS indisvalid, i.indisready::integer AS indisready,
       i.indislive::integer AS indislive, i.indimmediate::integer AS indimmediate,
       i.indisexclusion::integer AS indisexclusion, i.indnkeyatts, i.indnatts,
       COALESCE((to_jsonb(i)->>'indnullsnotdistinct')::boolean, false)::integer AS indnullsnotdistinct,
       pg_get_expr(i.indpred, i.indrelid) AS predicate,
       to_json(ARRAY(SELECT pg_get_indexdef(i.indexrelid, position, true)
                     FROM generate_series(1, i.indnkeyatts) AS position ORDER BY position)) AS key_definitions
FROM pg_index i
JOIN pg_class x ON x.oid = i.indexrelid
JOIN pg_namespace n ON n.oid = x.relnamespace
JOIN pg_class t ON t.oid = i.indrelid
JOIN pg_am am ON am.oid = x.relam
WHERE n.nspname = current_schema() AND x.relname = ?
SQL,
            [$name],
        );
    }

    private function assertExactColumnSet(string $table): void
    {
        $actual = DB::select(
            'SELECT a.attname FROM pg_attribute a WHERE a.attrelid = ?::regclass AND a.attnum > 0 AND NOT a.attisdropped ORDER BY a.attname',
            [$table],
        );
        $actualNames = array_map(static fn (object $column): string => $column->attname, $actual);
        $expectedNames = array_keys(LegalDocumentVersionOperationPostgresSchema::columns());
        sort($expectedNames);
        if ($actualNames !== $expectedNames) {
            throw new RuntimeException('legal_archive_version_operation_column_set_mismatch');
        }
    }

    private function assertIdSequenceDescriptor(string $table): void
    {
        $sequence = DB::selectOne(
            <<<'SQL'
SELECT sequence.relname AS sequence_name, sequence.relkind AS sequence_kind,
       dependency.deptype, dependency.refobjsubid,
       table_class.relname AS owned_table, attribute.attname AS owned_column,
       sequence_parameters.seqincrement, sequence_parameters.seqmin, sequence_parameters.seqmax,
       sequence_parameters.seqstart, sequence_parameters.seqcache,
       sequence_parameters.seqcycle::integer AS seqcycle
FROM pg_class sequence
JOIN pg_namespace sequence_namespace ON sequence_namespace.oid = sequence.relnamespace
JOIN pg_sequence sequence_parameters ON sequence_parameters.seqrelid = sequence.oid
JOIN pg_depend dependency ON dependency.classid = 'pg_class'::regclass
    AND dependency.objid = sequence.oid AND dependency.objsubid = 0
JOIN pg_class table_class ON table_class.oid = dependency.refobjid
JOIN pg_attribute attribute ON attribute.attrelid = table_class.oid AND attribute.attnum = dependency.refobjsubid
WHERE sequence_namespace.nspname = current_schema()
  AND sequence.relname = 'legal_archive_document_version_operations_id_seq'
SQL,
        );
        if ($sequence === null
            || $sequence->sequence_kind !== 'S'
            || $sequence->deptype !== 'a'
            || $sequence->owned_table !== $table
            || $sequence->owned_column !== 'id'
            || (int) $sequence->refobjsubid < 1
            || (int) $sequence->seqincrement !== 1
            || (int) $sequence->seqmin !== 1
            || (string) $sequence->seqmax !== '9223372036854775807'
            || (int) $sequence->seqstart !== 1
            || (int) $sequence->seqcache !== 1
            || (bool) $sequence->seqcycle
        ) {
            throw new RuntimeException('legal_archive_version_operation_sequence_descriptor_mismatch');
        }
    }

    private function assertExactIndexSet(string $table): void
    {
        $actual = DB::select(
            'SELECT index_class.relname FROM pg_index i JOIN pg_class index_class ON index_class.oid = i.indexrelid WHERE i.indrelid = ?::regclass ORDER BY index_class.relname',
            [$table],
        );
        $actualNames = array_map(static fn (object $index): string => $index->relname, $actual);
        $expectedNames = array_keys(LegalDocumentVersionOperationPostgresSchema::indexes());
        sort($expectedNames);
        if ($actualNames !== $expectedNames) {
            throw new RuntimeException('legal_archive_version_operation_index_set_mismatch');
        }
    }

    private function assertExactConstraintSet(string $table): void
    {
        $actual = DB::select(
            'SELECT c.conname FROM pg_constraint c WHERE c.conrelid = ?::regclass ORDER BY c.conname',
            [$table],
        );
        $expectedNames = ['legal_archive_document_version_operations_pkey'];
        foreach (LegalDocumentVersionOperationPostgresSchema::constraints() as $constraint) {
            $expectedNames[] = $constraint['name'];
        }
        sort($expectedNames);
        $actualNames = array_map(static fn (object $constraint): string => $constraint->conname, $actual);
        if (array_diff($actualNames, $expectedNames) !== []) {
            throw new RuntimeException('legal_archive_version_operation_constraint_set_mismatch');
        }
    }

    /** @param array{unique: bool, primary: bool, keys: list<string>, sql: string} $expected */
    private function sameIndexDescriptor(object $actual, string $table, array $expected): bool
    {
        $keys = is_string($actual->key_definitions)
            ? json_decode($actual->key_definitions, true, 512, JSON_THROW_ON_ERROR)
            : $actual->key_definitions;

        return $actual->table_name === $table
            && $actual->access_method === 'btree'
            && (bool) $actual->indisunique === $expected['unique']
            && (bool) $actual->indisprimary === $expected['primary']
            && (bool) $actual->indisvalid && (bool) $actual->indisready && (bool) $actual->indislive
            && (bool) $actual->indimmediate && ! (bool) $actual->indisexclusion
            && ! (bool) $actual->indnullsnotdistinct
            && (int) $actual->indnkeyatts === count($expected['keys'])
            && (int) $actual->indnatts === count($expected['keys'])
            && $actual->predicate === null
            && array_values($keys) === $expected['keys'];
    }
};
