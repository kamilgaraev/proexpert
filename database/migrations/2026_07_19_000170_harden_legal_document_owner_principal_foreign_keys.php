<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->constraints() as $name => $definition) {
            $actual = $this->constraint($name);
            if ($actual !== null && ! $this->matches($actual, $definition)) {
                throw new RuntimeException("legal_document_owner_fk_descriptor_mismatch:{$name}");
            }
            if ($actual === null) {
                DB::statement(
                    "ALTER TABLE legal_archive_documents ADD CONSTRAINT {$name} {$definition} NOT VALID",
                );
            }
            DB::statement("ALTER TABLE legal_archive_documents VALIDATE CONSTRAINT {$name}");
            $validated = $this->constraint($name);
            if ($validated === null || ! $this->matches($validated, $definition) || ! (bool) $validated->validated) {
                throw new RuntimeException("legal_document_owner_fk_descriptor_mismatch:{$name}");
            }
        }

        DB::statement(
            'ALTER TABLE legal_archive_documents '.
            'DROP CONSTRAINT IF EXISTS legal_archive_documents_created_by_user_id_foreign',
        );
        DB::statement(
            'ALTER TABLE legal_archive_documents DROP CONSTRAINT IF EXISTS legal_docs_owner_user_fk',
        );
    }

    public function down(): void
    {
        throw new RuntimeException('legal_document_access_migrations_are_forward_only');
    }

    private function constraints(): array
    {
        return [
            'legal_docs_created_by_user_restrict_fk' => 'FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT',
            'legal_docs_owner_user_restrict_fk' => 'FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE RESTRICT',
        ];
    }

    private function constraint(string $name): ?object
    {
        return DB::selectOne(<<<'SQL'
SELECT pg_get_constraintdef(constraint_record.oid, true) AS definition,
       constraint_record.convalidated::integer AS validated,
       constraint_record.condeferrable::integer AS deferrable,
       constraint_record.condeferred::integer AS deferred,
       referenced.relname AS referenced_table
FROM pg_constraint AS constraint_record
JOIN pg_class AS owner ON owner.oid = constraint_record.conrelid
JOIN pg_class AS referenced ON referenced.oid = constraint_record.confrelid
JOIN pg_namespace AS namespace ON namespace.oid = owner.relnamespace
WHERE namespace.nspname = current_schema()
  AND owner.relname = 'legal_archive_documents'
  AND constraint_record.conname = ?
  AND constraint_record.contype = 'f'
SQL, [$name]);
    }

    private function matches(object $constraint, string $expectedDefinition): bool
    {
        return $this->normalize((string) $constraint->definition) === $this->normalize($expectedDefinition)
            && (string) $constraint->referenced_table === 'users'
            && ! (bool) $constraint->deferrable
            && ! (bool) $constraint->deferred;
    }

    private function normalize(string $definition): string
    {
        $normalized = str_replace('not valid', '', strtolower($definition));

        return (string) preg_replace('/["\s()]+/', '', $normalized);
    }
};
