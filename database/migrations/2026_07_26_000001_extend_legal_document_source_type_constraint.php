<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'legal_archive_documents';
    private const CONSTRAINT = 'legal_documents_source_type_check';
    private const REPLACEMENT = 'legal_documents_source_type_check_v2';
    private const DEFINITION = "CHECK (source_type IS NULL OR source_type IN ('project','contract','supplementary_agreement','performance_act','purchase_order','payment_document','commercial_proposal','crm_deal','estimate','executive_document'))";

    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        if ($this->constraintMatches(self::CONSTRAINT)) {
            return;
        }

        if (! $this->constraintMatches(self::REPLACEMENT)) {
            DB::statement('ALTER TABLE '.self::TABLE.' DROP CONSTRAINT IF EXISTS '.self::REPLACEMENT);
            DB::unprepared('ALTER TABLE '.self::TABLE.' ADD CONSTRAINT '.self::REPLACEMENT.' '.self::DEFINITION.' NOT VALID');
        }

        DB::statement('ALTER TABLE '.self::TABLE.' VALIDATE CONSTRAINT '.self::REPLACEMENT);
        DB::statement('ALTER TABLE '.self::TABLE.' DROP CONSTRAINT IF EXISTS '.self::CONSTRAINT);
        DB::statement('ALTER TABLE '.self::TABLE.' RENAME CONSTRAINT '.self::REPLACEMENT.' TO '.self::CONSTRAINT);
    }

    public function down(): void
    {
        throw new RuntimeException('legal_document_access_migrations_are_forward_only');
    }

    private function constraintMatches(string $name): bool
    {
        $actual = DB::selectOne(
            <<<'SQL'
SELECT pg_get_constraintdef(c.oid, true) AS definition,
       c.condeferrable::integer AS deferrable,
       c.condeferred::integer AS deferred
FROM pg_constraint c
JOIN pg_class table_class ON table_class.oid = c.conrelid
JOIN pg_namespace namespace ON namespace.oid = table_class.relnamespace
WHERE namespace.nspname = current_schema()
  AND table_class.relname = ?
  AND c.conname = ?
SQL,
            [self::TABLE, $name],
        );

        return $actual !== null
            && ! (bool) $actual->deferrable
            && ! (bool) $actual->deferred
            && $this->normalize($actual->definition) === $this->normalize(self::DEFINITION);
    }

    private function normalize(mixed $definition): string
    {
        $normalized = strtolower((string) $definition);
        $normalized = str_replace('not valid', '', $normalized);
        $normalized = (string) preg_replace('/::[a-z_ ]+(?:\[\])?/', '', $normalized);
        $normalized = (string) preg_replace('/["\s()]+/', '', $normalized);
        $normalized = str_replace(['=anyarray[', ']'], ['in', ''], $normalized);

        return $normalized;
    }
};
