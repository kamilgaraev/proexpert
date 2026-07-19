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

    public function test_repair_command_requires_two_explicit_privileged_fences(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3).'/app/Console/Commands/ImmutableAuditRepairInvariantsCommand.php');

        self::assertIsString($source);
        self::assertStringContainsString('{--confirm-repair}', $source);
        self::assertStringContainsString("getenv('LEGAL_ARCHIVE_AUDIT_REPAIR_ENABLED') !== 'true'", $source);
        self::assertStringContainsString('repairPermanentInvariants', $source);
    }

    public function test_catalog_fingerprints_replace_marker_substring_checks_and_rebaseline_only_after_canonical_verification(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3).'/app/BusinessModules/Core/ImmutableAudit/Services/ImmutableAuditPhaseBInvariantService.php');

        self::assertIsString($source);
        self::assertStringNotContainsString('pg_get_functiondef', $source);
        self::assertStringNotContainsString(' LIKE ', $source);
        self::assertStringContainsString('pg_get_function_identity_arguments', $source);
        self::assertStringContainsString('p.prosrc', $source);
        self::assertStringContainsString('p.provolatile', $source);
        self::assertStringContainsString('owned_column', $source);
        self::assertStringContainsString("hash('sha256'", $source);
        $repair = strpos($source, 'public function repairPermanentInvariants');
        $canonical = strpos($source, '$this->installCanonicalCore($connection);', $repair);
        $baseline = strpos($source, '$this->captureBaseline($connection, true);', $repair);
        self::assertIsInt($repair);
        self::assertIsInt($canonical);
        self::assertIsInt($baseline);
        self::assertGreaterThan($canonical, $baseline);
    }
}
