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
        yield 'sequence' => ['sequence_exists', 'immutable_audit_sequence_missing'];
        yield 'allocator' => ['allocator_valid', 'immutable_audit_allocator_invalid'];
        yield 'guard trigger' => ['guard_trigger_valid', 'immutable_audit_writer_guard_invalid'];
        yield 'aggregate index' => ['aggregate_index_valid', 'immutable_audit_aggregate_index_invalid'];
        yield 'legacy index' => ['legacy_index_valid', 'immutable_audit_legacy_index_invalid'];
    }

    #[DataProvider('missingInvariantProvider')]
    public function test_each_missing_permanent_invariant_fails_closed(string $missing, string $reason): void
    {
        $snapshot = array_fill_keys([
            'sequence_exists',
            'allocator_valid',
            'guard_trigger_valid',
            'aggregate_index_valid',
            'legacy_index_valid',
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
            'sequence_exists' => true,
            'allocator_valid' => true,
            'guard_trigger_valid' => true,
            'aggregate_index_valid' => true,
            'legacy_index_valid' => true,
        ]);

        self::assertNull($service->failureReason($connection));
    }
}
