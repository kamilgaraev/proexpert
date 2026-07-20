<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\ImmutableAudit\Support;

final class ImmutableAuditInvariantDefinitions
{
    public const SEQUENCE_CREATE_SQL = 'CREATE SEQUENCE IF NOT EXISTS immutable_audit_sequence AS bigint INCREMENT BY 1 MINVALUE 1 NO MAXVALUE START WITH 1 CACHE 1 NO CYCLE';

    public const SEQUENCE_ALTER_SQL = 'ALTER SEQUENCE immutable_audit_sequence AS bigint INCREMENT BY 1 MINVALUE 1 NO MAXVALUE START WITH 1 CACHE 1 NO CYCLE OWNED BY immutable_audit_events.sequence_id';

    public const ALLOCATOR_BODY = <<<'SQL'
DECLARE rollout_phase text;
BEGIN
    SELECT phase INTO rollout_phase FROM immutable_audit_rollout WHERE singleton = true;
    IF rollout_phase <> 'phase_b' THEN
        RAISE EXCEPTION 'immutable_audit_writer_not_ready' USING ERRCODE = '55000';
    END IF;
    RETURN nextval('immutable_audit_sequence');
END;
SQL;

    public const SEQUENCE_SYNC_BODY = <<<'SQL'
BEGIN
    PERFORM setval('immutable_audit_sequence', GREATEST((SELECT last_value FROM immutable_audit_sequence), NEW.sequence_id), true);
    RETURN NEW;
END;
SQL;

    public const WRITER_GUARD_BODY = <<<'SQL'
DECLARE rollout immutable_audit_rollout%ROWTYPE;
BEGIN
    SELECT * INTO rollout FROM immutable_audit_rollout WHERE singleton = true;
    IF rollout.phase = 'phase_a' AND rollout.phase_a_expires_at IS NOT NULL AND clock_timestamp() > rollout.phase_a_expires_at THEN
        RAISE EXCEPTION 'immutable_audit_phase_a_expired' USING ERRCODE = '55000';
    END IF;
    IF rollout.phase = 'phase_b' AND (
        COALESCE(current_setting('most.immutable_audit_writer_version', true), '') <> '2'
        OR rollout.writer_credential_hash IS NULL
        OR encode(sha256(convert_to('immutable-audit-writer-credential:' || COALESCE(current_setting('most.immutable_audit_writer_credential', true), ''), 'UTF8')), 'hex') <> rollout.writer_credential_hash
    ) THEN
        RAISE EXCEPTION 'immutable_audit_writer_version_rejected' USING ERRCODE = '55000';
    END IF;
    RETURN NEW;
END;
SQL;

    public const APPEND_ONLY_BODY = <<<'SQL'
BEGIN
    RAISE EXCEPTION 'immutable audit records are append-only' USING ERRCODE = '55000';
END;
SQL;

    /** @return array<string,array<string,mixed>> */
    public static function expectedFunctions(): array
    {
        return [
            'allocator' => self::functionDescriptor(self::ALLOCATOR_BODY, 'bigint'),
            'writer_guard_function' => self::functionDescriptor(self::WRITER_GUARD_BODY, 'trigger'),
            'append_only_function' => self::functionDescriptor(self::APPEND_ONLY_BODY, 'trigger'),
            'sequence_sync_function' => self::functionDescriptor(self::SEQUENCE_SYNC_BODY, 'trigger'),
        ];
    }

    /** @return array<string,array<string,mixed>> */
    public static function expectedTriggers(): array
    {
        return [
            'writer_guard_trigger' => self::triggerDescriptor('immutable_audit_writer_guard', 'immutable_audit_writer_guard', 7),
            'append_only_trigger' => self::triggerDescriptor('immutable_audit_events_append_only', 'immutable_audit_prevent_mutation', 27),
            'sequence_sync_trigger' => self::triggerDescriptor('immutable_audit_sequence_sync', 'immutable_audit_sync_sequence_after_insert', 5),
        ];
    }

    /** @return array<string,mixed> */
    public static function expectedSequence(): array
    {
        return [
            'data_type' => 'bigint',
            'start_value' => '1',
            'min_value' => '1',
            'max_value' => '9223372036854775807',
            'increment_by' => '1',
            'cycle' => false,
            'cache_size' => '1',
            'owner_identity' => '$database_owner',
            'owned_table' => 'immutable_audit_events',
            'owned_column' => 'sequence_id',
        ];
    }

    public static function canonicalCoreSql(): string
    {
        return sprintf(<<<'SQL'
DO $canonical_owners$
DECLARE function_name text;
BEGIN
    ALTER TABLE immutable_audit_events OWNER TO CURRENT_USER;
    ALTER SEQUENCE immutable_audit_sequence OWNER TO CURRENT_USER;
    FOREACH function_name IN ARRAY ARRAY[
        'immutable_audit_allocate_sequence',
        'immutable_audit_sync_sequence_after_insert',
        'immutable_audit_writer_guard',
        'immutable_audit_prevent_mutation'
    ] LOOP
        IF to_regprocedure(format('%%I.%%I()', current_schema(), function_name)) IS NOT NULL THEN
            EXECUTE format('ALTER FUNCTION %%I.%%I() OWNER TO CURRENT_USER', current_schema(), function_name);
        END IF;
    END LOOP;
END
$canonical_owners$;
CREATE OR REPLACE FUNCTION immutable_audit_allocate_sequence() RETURNS bigint
LANGUAGE plpgsql VOLATILE CALLED ON NULL INPUT SECURITY INVOKER PARALLEL UNSAFE
SET search_path = pg_catalog, public AS $function$
%s
$function$;
CREATE OR REPLACE FUNCTION immutable_audit_sync_sequence_after_insert() RETURNS trigger
LANGUAGE plpgsql VOLATILE CALLED ON NULL INPUT SECURITY INVOKER PARALLEL UNSAFE
SET search_path = pg_catalog, public AS $function$
%s
$function$;
CREATE OR REPLACE FUNCTION immutable_audit_writer_guard() RETURNS trigger
LANGUAGE plpgsql VOLATILE CALLED ON NULL INPUT SECURITY INVOKER PARALLEL UNSAFE
SET search_path = pg_catalog, public AS $function$
%s
$function$;
CREATE OR REPLACE FUNCTION immutable_audit_prevent_mutation() RETURNS trigger
LANGUAGE plpgsql VOLATILE CALLED ON NULL INPUT SECURITY INVOKER PARALLEL UNSAFE
SET search_path = pg_catalog, public AS $function$
%s
$function$;
ALTER FUNCTION immutable_audit_allocate_sequence() SECURITY INVOKER CALLED ON NULL INPUT NOT LEAKPROOF PARALLEL UNSAFE;
ALTER FUNCTION immutable_audit_sync_sequence_after_insert() SECURITY INVOKER CALLED ON NULL INPUT NOT LEAKPROOF PARALLEL UNSAFE;
ALTER FUNCTION immutable_audit_writer_guard() SECURITY INVOKER CALLED ON NULL INPUT NOT LEAKPROOF PARALLEL UNSAFE;
ALTER FUNCTION immutable_audit_prevent_mutation() SECURITY INVOKER CALLED ON NULL INPUT NOT LEAKPROOF PARALLEL UNSAFE;
ALTER FUNCTION immutable_audit_allocate_sequence() COST 100;
ALTER FUNCTION immutable_audit_sync_sequence_after_insert() COST 100;
ALTER FUNCTION immutable_audit_writer_guard() COST 100;
ALTER FUNCTION immutable_audit_prevent_mutation() COST 100;
ALTER FUNCTION immutable_audit_allocate_sequence() RESET ALL;
ALTER FUNCTION immutable_audit_sync_sequence_after_insert() RESET ALL;
ALTER FUNCTION immutable_audit_writer_guard() RESET ALL;
ALTER FUNCTION immutable_audit_prevent_mutation() RESET ALL;
ALTER FUNCTION immutable_audit_allocate_sequence() SET search_path = pg_catalog, public;
ALTER FUNCTION immutable_audit_sync_sequence_after_insert() SET search_path = pg_catalog, public;
ALTER FUNCTION immutable_audit_writer_guard() SET search_path = pg_catalog, public;
ALTER FUNCTION immutable_audit_prevent_mutation() SET search_path = pg_catalog, public;
DO $acl$
DECLARE function_name text;
DECLARE grantee record;
BEGIN
    FOREACH function_name IN ARRAY ARRAY[
        'immutable_audit_allocate_sequence',
        'immutable_audit_sync_sequence_after_insert',
        'immutable_audit_writer_guard',
        'immutable_audit_prevent_mutation'
    ] LOOP
        FOR grantee IN
            SELECT DISTINCT expanded.grantee, pg_get_userbyid(expanded.grantee) AS role_name
            FROM pg_proc proc
            CROSS JOIN LATERAL aclexplode(COALESCE(proc.proacl, acldefault('f', proc.proowner))) expanded
            WHERE proc.oid = to_regprocedure(format('%%I.%%I()', current_schema(), function_name))
              AND expanded.grantee <> 0
        LOOP
            EXECUTE format(
                'REVOKE ALL PRIVILEGES ON FUNCTION %%I.%%I() FROM %%I CASCADE',
                current_schema(),
                function_name,
                grantee.role_name
            );
        END LOOP;
        EXECUTE format(
            'REVOKE ALL PRIVILEGES ON FUNCTION %%I.%%I() FROM PUBLIC CASCADE',
            current_schema(),
            function_name
        );
    END LOOP;
END
$acl$;
DROP TRIGGER IF EXISTS immutable_audit_writer_guard ON immutable_audit_events;
CREATE TRIGGER immutable_audit_writer_guard BEFORE INSERT ON immutable_audit_events FOR EACH ROW EXECUTE FUNCTION immutable_audit_writer_guard();
DROP TRIGGER IF EXISTS immutable_audit_events_append_only ON immutable_audit_events;
CREATE TRIGGER immutable_audit_events_append_only BEFORE UPDATE OR DELETE ON immutable_audit_events FOR EACH ROW EXECUTE FUNCTION immutable_audit_prevent_mutation();
DROP TRIGGER IF EXISTS immutable_audit_sequence_sync ON immutable_audit_events;
CREATE TRIGGER immutable_audit_sequence_sync AFTER INSERT ON immutable_audit_events FOR EACH ROW EXECUTE FUNCTION immutable_audit_sync_sequence_after_insert();
SQL, self::ALLOCATOR_BODY, self::SEQUENCE_SYNC_BODY, self::WRITER_GUARD_BODY, self::APPEND_ONLY_BODY);
    }

    /** @return array<string,mixed> */
    private static function functionDescriptor(string $body, string $result): array
    {
        return [
            'prosrc' => self::normalizeBody($body),
            'identity_arguments' => '',
            'result' => $result,
            'language' => 'plpgsql',
            'volatility' => 'v',
            'security_definer' => false,
            'owner_matches_relation' => true,
            'owner_identity' => '$database_owner',
            'relation_owner_identity' => '$database_owner',
            'acl' => [],
            'public_execute' => false,
            'cost' => '100',
            'rows' => '0',
            'support' => '-',
            'config' => ['search_path=pg_catalog, public'],
            'strict' => false,
            'leakproof' => false,
            'parallel' => 'u',
            'kind' => 'f',
        ];
    }

    /** @return array<string,mixed> */
    private static function triggerDescriptor(string $name, string $function, int $type): array
    {
        return [
            'name' => $name,
            'enabled' => 'O',
            'internal' => false,
            'relation' => 'immutable_audit_events',
            'function_name' => $function,
            'function_schema_identity' => '$current_schema',
            'function_oid_identity' => '$canonical_function_oid',
            'function_identity_arguments' => '',
            'function_oid_matches_expected' => true,
            'type' => $type,
            'definition' => self::normalizeTriggerDefinition(sprintf(
                'CREATE TRIGGER %s %s ON immutable_audit_events FOR EACH ROW EXECUTE FUNCTION %s()',
                $name,
                $type === 7 ? 'BEFORE INSERT' : ($type === 27 ? 'BEFORE DELETE OR UPDATE' : 'AFTER INSERT'),
                $function,
            )),
            'when' => '',
            'arguments_hex' => '',
            'constraint_oid' => '0',
            'constraint_type' => '',
            'deferrable' => false,
            'initially_deferred' => false,
            'parent_trigger_oid' => '0',
            'parent_relation' => '',
            'parent_trigger' => '',
            'old_transition_table' => '',
            'new_transition_table' => '',
            'function_dependency' => true,
        ];
    }

    private static function normalizeTriggerDefinition(string $definition): string
    {
        return strtolower((string) preg_replace('/[;\s"]+/', '', $definition));
    }

    private static function normalizeBody(string $body): string
    {
        return trim(str_replace("\r\n", "\n", $body));
    }
}
