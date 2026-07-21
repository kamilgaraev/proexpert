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
        foreach (LegalDocumentVersionOperationPostgresSchema::constraints() as $expected) {
            $actual = $this->constraint($expected['name']);
            if ($actual !== null && ! LegalDocumentVersionOperationPostgresSchema::constraintMatches($actual, $expected)) {
                if ($expected['name'] === 'legal_archive_version_operations_state_check') {
                    DB::statement("ALTER TABLE {$expected['table']} DROP CONSTRAINT {$expected['name']}");
                    $actual = null;
                } else {
                    throw new RuntimeException("legal_archive_version_operation_constraint_descriptor_mismatch:{$expected['name']}");
                }
            }
            if ($actual === null) {
                DB::statement("ALTER TABLE {$expected['table']} ADD CONSTRAINT {$expected['name']} {$expected['definition']} NOT VALID");
                $actual = $this->constraint($expected['name']);
            }
            if ($actual === null || ! LegalDocumentVersionOperationPostgresSchema::constraintMatches($actual, $expected, false)) {
                throw new RuntimeException("legal_archive_version_operation_constraint_descriptor_mismatch:{$expected['name']}");
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('legal_archive_version_operation_migrations_are_forward_only');
    }

    private function constraint(string $name): ?object
    {
        return DB::selectOne(<<<'SQL'
SELECT n.nspname AS table_schema, t.relname AS table_name, c.contype, c.condeferrable, c.condeferred, c.convalidated,
       referenced_namespace.nspname AS referenced_schema, referenced_table.relname AS referenced_table,
       c.confupdtype, c.confdeltype, c.confmatchtype,
       to_json(ARRAY(SELECT a.attname FROM unnest(c.conkey) WITH ORDINALITY key(attnum, position)
                     JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = key.attnum ORDER BY key.position)) AS key_columns,
       to_json(ARRAY(SELECT a.attname FROM unnest(c.confkey) WITH ORDINALITY key(attnum, position)
                     JOIN pg_attribute a ON a.attrelid = c.confrelid AND a.attnum = key.attnum ORDER BY key.position)) AS referenced_key_columns,
       pg_get_constraintdef(c.oid, true) AS definition
FROM pg_constraint c
JOIN pg_class t ON t.oid = c.conrelid
JOIN pg_namespace n ON n.oid = c.connamespace
LEFT JOIN pg_class referenced_table ON referenced_table.oid = c.confrelid
LEFT JOIN pg_namespace referenced_namespace ON referenced_namespace.oid = referenced_table.relnamespace
WHERE n.nspname = current_schema() AND c.conname = ?
SQL, [$name]);
    }
};
