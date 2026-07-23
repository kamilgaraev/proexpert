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

        $actual = DB::selectOne(<<<'SQL'
SELECT pg_get_constraintdef(constraint_row.oid, true) AS definition
FROM pg_constraint constraint_row
JOIN pg_class table_row ON table_row.oid = constraint_row.conrelid
JOIN pg_namespace namespace_row ON namespace_row.oid = table_row.relnamespace
WHERE namespace_row.nspname = current_schema()
  AND table_row.relname = 'legal_document_access_grants'
  AND constraint_row.conname = 'legal_document_access_abilities_check'
SQL);
        if ($actual === null) {
            throw new RuntimeException('legal_document_access_abilities_predecessor_missing');
        }

        $signaturePredecessor = <<<'SQL'
CHECK (jsonb_typeof(abilities) = 'array'::text AND jsonb_array_length(abilities) > 0 AND abilities <@ '["view", "comment", "approve", "request_signature", "sign", "verify_signature", "download", "manage"]'::jsonb AND (NOT abilities ? 'manage'::text OR subject_kind::text = 'internal_user'::text))
SQL;
        $basePredecessor = <<<'SQL'
CHECK (jsonb_typeof(abilities) = 'array'::text AND jsonb_array_length(abilities) > 0 AND abilities <@ '["view", "comment", "approve", "sign", "download", "manage"]'::jsonb AND (NOT abilities ? 'manage'::text OR subject_kind::text = 'internal_user'::text))
SQL;
        $next = <<<'SQL'
CHECK (jsonb_typeof(abilities) = 'array'::text AND jsonb_array_length(abilities) > 0 AND abilities <@ '["view", "comment", "approve", "request_signature", "sign", "verify_signature", "download", "edit", "manage"]'::jsonb AND (NOT abilities ? 'manage'::text OR subject_kind::text = 'internal_user'::text))
SQL;

        $normalized = $this->normalize((string) $actual->definition);
        if ($normalized === $this->normalize($next)) {
            return;
        }
        if (! in_array($normalized, [
            $this->normalize($basePredecessor),
            $this->normalize($signaturePredecessor),
        ], true)) {
            throw new RuntimeException('legal_document_access_abilities_predecessor_mismatch');
        }

        DB::statement('ALTER TABLE legal_document_access_grants DROP CONSTRAINT legal_document_access_abilities_check');
        DB::unprepared("ALTER TABLE legal_document_access_grants ADD CONSTRAINT legal_document_access_abilities_check {$next} NOT VALID");
    }

    public function down(): void
    {
        throw new RuntimeException('legal_document_access_ability_migrations_are_forward_only');
    }

    private function normalize(string $definition): string
    {
        $definition = strtolower($definition);
        $definition = str_replace('not valid', '', $definition);
        $definition = (string) preg_replace('/::[a-z_ ]+(?:\[\])?/', '', $definition);

        return (string) preg_replace('/["()\s]+/', '', $definition);
    }
};
