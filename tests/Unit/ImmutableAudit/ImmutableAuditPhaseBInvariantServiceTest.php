<?php

declare(strict_types=1);

namespace Tests\Unit\ImmutableAudit;

use App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditPhaseBInvariantService;
use Illuminate\Database\ConnectionInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ImmutableAuditPhaseBInvariantServiceTest extends TestCase
{
    /** @return iterable<string, array{string,string}> */
    public static function missingInvariantProvider(): iterable
    {
        yield 'sequence' => ['sequence', 'immutable_audit_sequence_invalid'];
        yield 'allocator' => ['allocator', 'immutable_audit_allocator_invalid'];
        yield 'guard function' => ['writer_guard_function', 'immutable_audit_writer_guard_invalid'];
        yield 'guard trigger' => ['writer_guard_trigger', 'immutable_audit_writer_guard_invalid'];
        yield 'append-only function' => ['append_only_function', 'immutable_audit_append_only_invalid'];
        yield 'append-only trigger' => ['append_only_trigger', 'immutable_audit_append_only_invalid'];
        yield 'sequence sync function' => ['sequence_sync_function', 'immutable_audit_sequence_sync_invalid'];
        yield 'sequence sync trigger' => ['sequence_sync_trigger', 'immutable_audit_sequence_sync_invalid'];
        yield 'aggregate index' => ['aggregate_index', 'immutable_audit_aggregate_index_invalid'];
        yield 'legacy index' => ['legacy_index', 'immutable_audit_legacy_index_invalid'];
    }

    #[DataProvider('missingInvariantProvider')]
    public function test_each_missing_permanent_invariant_fails_closed(string $missing, string $reason): void
    {
        $snapshot = array_fill_keys([
            'sequence', 'allocator', 'writer_guard_function', 'writer_guard_trigger',
            'append_only_function', 'append_only_trigger', 'sequence_sync_function',
            'sequence_sync_trigger', 'aggregate_index', 'legacy_index',
        ], true);
        $snapshot[$missing] = false;
        $connection = $this->createMock(ConnectionInterface::class);
        $service = new ImmutableAuditPhaseBInvariantService(static fn (): array => $snapshot);

        self::assertSame($reason, $service->failureReason($connection));
    }

    public function test_complete_permanent_invariant_snapshot_is_ready(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $service = new ImmutableAuditPhaseBInvariantService(static fn (): array => [
            'sequence' => true,
            'allocator' => true,
            'writer_guard_function' => true,
            'writer_guard_trigger' => true,
            'append_only_function' => true,
            'append_only_trigger' => true,
            'sequence_sync_function' => true,
            'sequence_sync_trigger' => true,
            'aggregate_index' => true,
            'legacy_index' => true,
        ]);

        self::assertNull($service->failureReason($connection));
    }

    public function test_repair_command_requires_explicit_flag_and_fresh_database_drain_proof(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3).'/app/Console/Commands/ImmutableAuditRepairInvariantsCommand.php');
        $rollout = file_get_contents(dirname(__DIR__, 3).'/app/BusinessModules/Core/ImmutableAudit/Services/ImmutableAuditRolloutService.php');

        self::assertIsString($source);
        self::assertIsString($rollout);
        self::assertStringContainsString('{--confirm-repair}', $source);
        self::assertStringContainsString("getenv('LEGAL_ARCHIVE_AUDIT_REPAIR_ENABLED') !== 'true'", $source);
        self::assertStringContainsString('repairPermanentInvariants', $source);
        self::assertStringContainsString('immutable_audit_phase_b_index_prep', $rollout);
        self::assertStringContainsString('immutable_audit_writer_fence', $rollout);
        self::assertStringContainsString('LOCK TABLE immutable_audit_events IN ACCESS EXCLUSIVE MODE', $rollout);
        self::assertStringContainsString('immutable_audit_phase_b_drain_marker_required', $rollout);
    }

    public function test_expected_catalog_descriptors_are_versioned_in_code_and_database_baseline_is_not_a_trust_anchor(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3).'/app/BusinessModules/Core/ImmutableAudit/Services/ImmutableAuditPhaseBInvariantService.php');
        $definitions = file_get_contents(dirname(__DIR__, 3).'/app/BusinessModules/Core/ImmutableAudit/Support/ImmutableAuditInvariantDefinitions.php');
        $migration = file_get_contents(dirname(__DIR__, 3).'/database/migrations/2026_07_19_000310_extend_immutable_audit_for_legal_documents.php');

        self::assertIsString($source);
        self::assertIsString($definitions);
        self::assertIsString($migration);
        self::assertStringNotContainsString('pg_get_functiondef', $source);
        self::assertStringNotContainsString(' LIKE ', $source);
        self::assertSame(1, substr_count($source.$definitions.$migration, 'immutable_audit_invariant_baselines'));
        self::assertStringContainsString('DROP TABLE IF EXISTS immutable_audit_invariant_baselines', $source);
        self::assertStringNotContainsString('captureBaseline', $source);
        self::assertStringContainsString('pg_get_function_identity_arguments', $source);
        self::assertStringContainsString('p.prosrc', $source);
        self::assertStringContainsString('p.provolatile', $source);
        self::assertStringContainsString('p.prosecdef', $source);
        self::assertStringContainsString('p.proconfig', $source);
        self::assertStringContainsString('p.proisstrict', $source);
        self::assertStringContainsString('p.proleakproof', $source);
        self::assertStringContainsString('p.proparallel', $source);
        self::assertStringContainsString('p.prokind', $source);
        self::assertStringContainsString('owner_matches_relation', $source);
        foreach (['pg_get_triggerdef', 't.tgqual', 't.tgargs', 't.tgconstraint', 't.tgparentid', 't.tgoldtable', 't.tgnewtable', 'function_dependency'] as $catalogField) {
            self::assertStringContainsString($catalogField, $source);
        }
        foreach (['aclexplode', 'public_execute', 'p.procost', 'p.prorows', 'p.prosupport', 'owner_identity', 'relation_owner_identity'] as $catalogField) {
            self::assertStringContainsString($catalogField, $source);
        }
        self::assertStringContainsString('owned_column', $source);
        self::assertStringContainsString("'start_value' => '1'", $definitions);
        self::assertStringContainsString("'security_definer' => false", $definitions);
        self::assertStringContainsString("'config' => ['search_path=pg_catalog, public']", $definitions);
        self::assertStringContainsString("'strict' => false", $definitions);
        self::assertStringContainsString("'leakproof' => false", $definitions);
        self::assertStringContainsString("'parallel' => 'u'", $definitions);
        self::assertStringContainsString("'kind' => 'f'", $definitions);
        self::assertStringContainsString("'owner_identity' => '\$database_owner'", $definitions);
        self::assertStringContainsString("'relation_owner_identity' => '\$database_owner'", $definitions);
        self::assertStringContainsString("'acl' => ['\$database_owner:EXECUTE:false:\$database_owner']", $definitions);
        self::assertStringContainsString("'public_execute' => false", $definitions);
        self::assertStringContainsString("'cost' => '100'", $definitions);
        self::assertStringContainsString("'rows' => '0'", $definitions);
        self::assertStringContainsString("'support' => '-'", $definitions);
        self::assertStringContainsString("'when' => ''", $definitions);
        self::assertStringContainsString("'arguments_hex' => ''", $definitions);
        self::assertStringContainsString("'constraint_oid' => '0'", $definitions);
        self::assertStringContainsString("'parent_trigger_oid' => '0'", $definitions);
    }

    public function test_canonical_ddl_explicitly_resets_every_security_attribute_and_sequence_start_metadata(): void
    {
        $definitions = file_get_contents(dirname(__DIR__, 3).'/app/BusinessModules/Core/ImmutableAudit/Support/ImmutableAuditInvariantDefinitions.php');

        self::assertIsString($definitions);
        self::assertStringContainsString('SECURITY INVOKER', $definitions);
        self::assertStringContainsString('CALLED ON NULL INPUT', $definitions);
        self::assertStringContainsString('PARALLEL UNSAFE', $definitions);
        self::assertStringContainsString('ALTER FUNCTION', $definitions);
        self::assertStringContainsString('RESET ALL', $definitions);
        self::assertStringContainsString('SET search_path = pg_catalog, public', $definitions);
        self::assertStringContainsString('NOT LEAKPROOF', $definitions);
        self::assertStringContainsString('START WITH 1', $definitions);
        self::assertStringContainsString('COST 100', $definitions);
        self::assertStringContainsString('REVOKE ALL ON FUNCTION', $definitions);
        self::assertStringContainsString('GRANT EXECUTE ON FUNCTION', $definitions);
    }

    public function test_repair_uses_global_lock_order_rechecks_marker_under_writer_fence_and_consumes_it_atomically(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3).'/app/BusinessModules/Core/ImmutableAudit/Services/ImmutableAuditRolloutService.php');

        self::assertIsString($source);
        $start = strpos($source, 'public function repairPermanentInvariants');
        self::assertIsInt($start);
        $repair = substr($source, $start);
        $ordered = [
            "['immutable_audit_phase_b_index_prep']",
            '$this->invariants->preparePhaseBIndexes($connection)',
            "pg_advisory_unlock(hashtextextended(?, 0))', ['immutable_audit_phase_b_index_prep']",
            "['immutable_audit_writer_fence']",
            'LOCK TABLE immutable_audit_events IN ACCESS EXCLUSIVE MODE',
            '$this->lockedRolloutMarker($connection, $ttl, true)',
            '$this->assertCutoverMarker($marker, $credentialHash, \'phase_b\')',
            '$this->invariants->installCanonicalCore($connection)',
            '$this->invariants->assertPermanentInvariants($connection)',
            '->where(\'drain_marker\', (string) $marker->drain_marker)',
        ];
        $previous = -1;
        foreach ($ordered as $needle) {
            $position = strpos($repair, $needle, $previous + 1);
            self::assertIsInt($position, $needle);
            self::assertGreaterThan($previous, $position, $needle);
            $previous = $position;
        }
        self::assertStringNotContainsString("['immutable_audit_invariant_repair']", $repair);
    }
}
