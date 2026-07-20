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
        foreach (DB::select("SELECT c.conname, c.conrelid::regclass::text AS table_name FROM pg_constraint c JOIN pg_namespace n ON n.oid=(SELECT relnamespace FROM pg_class WHERE oid=c.conrelid) WHERE n.nspname=current_schema() AND (c.conname LIKE 'legal_signature_%' OR c.conname LIKE 'legal_document_signatures_%')") as $constraint) {
            DB::statement("ALTER TABLE {$constraint->table_name} VALIDATE CONSTRAINT {$constraint->conname}");
        }
        DB::statement('ALTER TABLE legal_document_access_grants VALIDATE CONSTRAINT legal_document_access_abilities_check');
        $invalid = DB::selectOne("SELECT count(*) AS aggregate FROM pg_constraint c JOIN pg_namespace n ON n.oid=(SELECT relnamespace FROM pg_class WHERE oid=c.conrelid) WHERE n.nspname=current_schema() AND NOT c.convalidated AND (c.conname LIKE 'legal_signature_%' OR c.conname LIKE 'legal_document_signatures_%')");
        if ((int) ($invalid->aggregate ?? 0) !== 0) {
            throw new RuntimeException('legal_signature_constraints_not_validated');
        }
        $access = DB::selectOne("SELECT c.convalidated::integer AS validated, pg_get_constraintdef(c.oid, true) AS definition FROM pg_constraint c JOIN pg_class t ON t.oid=c.conrelid JOIN pg_namespace n ON n.oid=t.relnamespace WHERE n.nspname=current_schema() AND t.relname='legal_document_access_grants' AND c.conname='legal_document_access_abilities_check'");
        if ($access === null || ! (bool) $access->validated
            || ! str_contains((string) $access->definition, 'request_signature')
            || ! str_contains((string) $access->definition, 'verify_signature')) {
            throw new RuntimeException('legal_document_access_abilities_validation_failed');
        }
    }

    public function down(): void
    {
        throw new RuntimeException('legal_document_signature_migrations_are_forward_only');
    }
};
