<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const FUNCTION = 'legal_archive_versions_immutable_guard';

    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        $function = $this->functionDescriptor();
        if ($function === null || $this->isPredecessorFunction($function)) {
            DB::unprepared($this->desiredFunctionSql());
            $function = $this->functionDescriptor();
        }
        if ($function === null || ! $this->isDesiredFunction($function)) {
            throw new RuntimeException('legal_archive_version_operation_function_descriptor_mismatch');
        }

        $trigger = $this->triggerDescriptor();
        if ($trigger === null) {
            DB::statement('CREATE TRIGGER legal_archive_versions_immutable_guard BEFORE UPDATE OR DELETE ON legal_archive_document_versions FOR EACH ROW EXECUTE FUNCTION legal_archive_versions_immutable_guard()');
            $trigger = $this->triggerDescriptor();
        }
        if ($trigger === null || ! $this->isDesiredTrigger($trigger)) {
            throw new RuntimeException('legal_archive_version_operation_trigger_descriptor_mismatch');
        }
    }

    public function down(): void
    {
        throw new RuntimeException('legal_archive_version_operation_migrations_are_forward_only');
    }

    private function functionDescriptor(): ?object
    {
        return DB::selectOne(
            <<<'SQL'
SELECT n.nspname AS schema_name, current_schema() AS expected_schema, p.proname, l.lanname AS language_name,
       p.prorettype::regtype::text AS return_type, p.provolatile,
       p.prosecdef::integer AS prosecdef, p.proleakproof::integer AS proleakproof,
       p.proisstrict::integer AS proisstrict, p.proconfig, p.prosrc,
       pg_get_functiondef(p.oid) AS function_definition
FROM pg_proc p
JOIN pg_namespace n ON n.oid = p.pronamespace
JOIN pg_language l ON l.oid = p.prolang
WHERE n.nspname = current_schema() AND p.proname = ? AND p.pronargs = 0
SQL,
            [self::FUNCTION],
        );
    }

    private function triggerDescriptor(): ?object
    {
        return DB::selectOne(
            <<<'SQL'
SELECT table_class.relname AS table_name, current_schema() AS expected_schema, trigger.tgname,
       trigger.tgtype, trigger.tgenabled, trigger.tgisinternal::integer AS tgisinternal,
       trigger.tgconstraint, function_namespace.nspname AS function_schema,
       function.proname AS function_name, pg_get_triggerdef(trigger.oid, true) AS trigger_definition
FROM pg_trigger trigger
JOIN pg_class table_class ON table_class.oid = trigger.tgrelid
JOIN pg_namespace table_namespace ON table_namespace.oid = table_class.relnamespace
JOIN pg_proc function ON function.oid = trigger.tgfoid
JOIN pg_namespace function_namespace ON function_namespace.oid = function.pronamespace
WHERE table_namespace.nspname = current_schema()
  AND table_class.relname = 'legal_archive_document_versions'
  AND trigger.tgname = 'legal_archive_versions_immutable_guard'
SQL,
        );
    }

    private function isDesiredFunction(object $actual): bool
    {
        return $this->hasSafeFunctionDescriptor($actual)
            && $this->configuration($actual->proconfig) === ['search_path=pg_catalog']
            && $this->normalizeBody($actual->prosrc) === $this->normalizeBody($this->desiredBody())
            && str_contains(strtolower((string) $actual->function_definition), 'set search_path to \'pg_catalog\'');
    }

    private function isPredecessorFunction(object $actual): bool
    {
        return $this->hasSafeFunctionDescriptor($actual)
            && $this->configuration($actual->proconfig) === []
            && $this->normalizeBody($actual->prosrc) === $this->normalizeBody($this->predecessorBody());
    }

    private function hasSafeFunctionDescriptor(object $actual): bool
    {
        return $actual->schema_name === $actual->expected_schema
            && $actual->proname === self::FUNCTION
            && $actual->language_name === 'plpgsql'
            && $actual->return_type === 'trigger'
            && $actual->provolatile === 'v'
            && ! (bool) $actual->prosecdef
            && ! (bool) $actual->proleakproof
            && ! (bool) $actual->proisstrict;
    }

    private function isDesiredTrigger(object $actual): bool
    {
        return $actual->table_name === 'legal_archive_document_versions'
            && $actual->tgname === self::FUNCTION
            && (int) $actual->tgtype === 27
            && $actual->tgenabled === 'O'
            && ! (bool) $actual->tgisinternal
            && (int) $actual->tgconstraint === 0
            && $actual->function_schema === $actual->expected_schema
            && $actual->function_name === self::FUNCTION;
    }

    /** @return list<string> */
    private function configuration(mixed $configuration): array
    {
        if ($configuration === null) {
            return [];
        }
        if (is_array($configuration)) {
            return array_values(array_map('strval', $configuration));
        }
        $value = trim((string) $configuration, '{}');

        return $value === '' ? [] : str_getcsv($value);
    }

    private function desiredFunctionSql(): string
    {
        return 'CREATE OR REPLACE FUNCTION legal_archive_versions_immutable_guard() RETURNS trigger '
            .'LANGUAGE plpgsql VOLATILE SECURITY INVOKER SET search_path TO pg_catalog AS $function$'
            .$this->desiredBody().'$function$;';
    }

    private function desiredBody(): string
    {
        return <<<'PLPGSQL'

BEGIN
    IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'legal_archive_version_delete_forbidden'; END IF;
    IF current_setting('most.legal_archive_version_mutation', true) IS DISTINCT FROM 'service' THEN
        RAISE EXCEPTION 'legal_archive_version_update_forbidden';
    END IF;
    IF OLD.status IN ('signed', 'frozen') THEN RAISE EXCEPTION 'legal_archive_frozen_version_update_forbidden'; END IF;
    IF (OLD.document_id, OLD.document_file_id, OLD.organization_id, OLD.version_number, OLD.version_label,
        OLD.status, OLD.file_path, OLD.original_filename, OLD.mime_type, OLD.size_bytes, OLD.content_hash,
        OLD.metadata_hash, OLD.uploaded_by_user_id, OLD.uploaded_at, OLD.metadata, OLD.created_at)
       IS DISTINCT FROM
       (NEW.document_id, NEW.document_file_id, NEW.organization_id, NEW.version_number, NEW.version_label,
        NEW.status, NEW.file_path, NEW.original_filename, NEW.mime_type, NEW.size_bytes, NEW.content_hash,
        NEW.metadata_hash, NEW.uploaded_by_user_id, NEW.uploaded_at, NEW.metadata, NEW.created_at) THEN
        RAISE EXCEPTION 'legal_archive_version_content_update_forbidden';
    END IF;
    IF OLD.processing_status IS DISTINCT FROM NEW.processing_status
       AND NOT ((OLD.processing_status = 'quarantine' AND NEW.processing_status IN ('ready', 'failed'))
           OR (OLD.processing_status = 'failed' AND NEW.processing_status = 'quarantine')) THEN
        RAISE EXCEPTION 'legal_archive_version_transition_forbidden';
    END IF;
    RETURN NEW;
END;

PLPGSQL;
    }

    private function predecessorBody(): string
    {
        return str_replace(
            "AND NOT ((OLD.processing_status = 'quarantine' AND NEW.processing_status IN ('ready', 'failed'))\n           OR (OLD.processing_status = 'failed' AND NEW.processing_status = 'quarantine'))",
            "AND NOT (OLD.processing_status = 'quarantine' AND NEW.processing_status IN ('ready', 'failed'))",
            $this->desiredBody(),
        );
    }

    private function normalizeBody(mixed $body): string
    {
        return (string) preg_replace('/\s+/', '', strtolower(trim((string) $body)));
    }

};
