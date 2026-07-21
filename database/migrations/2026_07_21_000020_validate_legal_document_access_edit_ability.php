<?php

declare(strict_types=1);

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

        $constraint = DB::selectOne(<<<'SQL'
SELECT constraint_row.convalidated::integer AS validated,
       pg_get_constraintdef(constraint_row.oid, true) AS definition
FROM pg_constraint constraint_row
JOIN pg_class table_row ON table_row.oid = constraint_row.conrelid
JOIN pg_namespace namespace_row ON namespace_row.oid = table_row.relnamespace
WHERE namespace_row.nspname = current_schema()
  AND table_row.relname = 'legal_document_access_grants'
  AND constraint_row.conname = 'legal_document_access_abilities_check'
SQL);
        if ($constraint === null || $this->normalize((string) $constraint->definition) !== $this->normalize($this->definition())) {
            throw new RuntimeException('legal_document_access_abilities_descriptor_mismatch');
        }
        if (! (bool) $constraint->validated) {
            DB::statement('ALTER TABLE legal_document_access_grants VALIDATE CONSTRAINT legal_document_access_abilities_check');
        }
    }

    public function down(): void
    {
        throw new RuntimeException('legal_document_access_ability_migrations_are_forward_only');
    }

    private function definition(): string
    {
        return <<<'SQL'
CHECK (jsonb_typeof(abilities) = 'array'::text AND jsonb_array_length(abilities) > 0 AND abilities <@ '["view", "comment", "approve", "request_signature", "sign", "verify_signature", "download", "edit", "manage"]'::jsonb AND (NOT abilities ? 'manage'::text OR subject_kind::text = 'internal_user'::text))
SQL;
    }

    private function normalize(string $definition): string
    {
        $definition = strtolower($definition);
        $definition = str_replace('not valid', '', $definition);
        $definition = (string) preg_replace('/::[a-z_ ]+(?:\[\])?/', '', $definition);

        return (string) preg_replace('/["()\s]+/', '', $definition);
    }
};
