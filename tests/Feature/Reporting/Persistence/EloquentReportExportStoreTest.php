<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting\Persistence;

use App\BusinessModules\Core\Reporting\Application\Audit\ReportTransitionAudit;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportDispatchIntentStore;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExecutionClock;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExportStore;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\EloquentReportExportStore;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportExportRecord;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportRunRecord;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\ReportExportHydrator;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class EloquentReportExportStoreTest extends TestCase
{
    public function test_export_store_contract_has_the_closed_seven_method_surface(): void
    {
        $methods = array_map(static fn ($method): string => $method->getName(), (new ReflectionClass(ReportExportStore::class))->getMethods());
        sort($methods);

        self::assertSame([
            'cancel',
            'createOrReuse',
            'fail',
            'get',
            'sealReady',
            'startRendering',
            'startUploading',
        ], $methods);
    }

    public function test_export_store_enforces_exact_960_second_lease_fence(): void
    {
        $store = $this->store();
        $method = $this->method('assertLeaseInput');
        $now = new DateTimeImmutable('2026-07-28T10:00:00.123456Z');

        $method->invoke($store, '00000000-0000-4000-8000-000000000001', $now->modify('+960 seconds'), $now);

        $this->expectException(ReportContractException::class);
        $method->invoke($store, '00000000-0000-4000-8000-000000000001', $now->modify('+959 seconds'), $now);
    }

    public function test_live_export_lease_requires_stored_fenced_duration(): void
    {
        $store = $this->store();
        $method = $this->method('hasLiveLease');
        $heartbeatAt = new DateTimeImmutable('2026-07-28T10:00:00.123456Z');
        $record = new ReportExportRecord;
        $record->setRawAttributes([
            'execution_lease_token' => '00000000-0000-4000-8000-000000000001',
            'execution_lease_expires_at' => '2026-07-28 10:16:00.123456+00',
            'execution_heartbeat_at' => '2026-07-28 10:00:00.123456+00',
        ], true);

        self::assertTrue($method->invoke($store, $record, '00000000-0000-4000-8000-000000000001', $heartbeatAt->modify('+1 second')));

        $record->setRawAttributes([
            'execution_lease_token' => '00000000-0000-4000-8000-000000000001',
            'execution_lease_expires_at' => '2026-07-28 10:16:01.123456+00',
            'execution_heartbeat_at' => '2026-07-28 10:00:00.123456+00',
        ], true);
        self::assertFalse($method->invoke($store, $record, '00000000-0000-4000-8000-000000000001', $heartbeatAt->modify('+1 second')));
    }

    public function test_parent_identity_pairs_cover_complete_task4e_export_matrix(): void
    {
        $pairs = $this->method('parentIdentityPairs')->invoke(
            $this->store(),
            $this->exportRecord($this->identityAttributes('export')),
            $this->runRecord($this->identityAttributes('run')),
        );

        self::assertCount(30, $pairs);
        self::assertContains(['export-query_hash', 'run-query_hash'], $pairs);
        self::assertContains(['export-source_hash', 'run-source_hash'], $pairs);
        self::assertContains(['export-result_hash', 'run-result_hash'], $pairs);
        self::assertContains(['export-snapshot_sealed_payload_hash', 'run-snapshot_sealed_payload_hash'], $pairs);
        self::assertContains(['export-data_classification', 'run-data_classification'], $pairs);
        self::assertContains(['export-renderer_version', 'run-renderer_version'], $pairs);
    }

    public function test_payload_copies_parent_run_correlation_lineage(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/BusinessModules/Core/Reporting/Infrastructure/Persistence/EloquentReportExportStore.php');

        self::assertIsString($source);
        self::assertStringContainsString("->whereKey(\$source->run->id)", $source);
        self::assertStringContainsString("'correlation_lineage_id' => \$correlationLineageId", $source);
    }

    private function method(string $name): ReflectionMethod
    {
        $method = new ReflectionMethod(EloquentReportExportStore::class, $name);
        $method->setAccessible(true);

        return $method;
    }

    private function store(): EloquentReportExportStore
    {
        return new EloquentReportExportStore(
            new class implements ReportExecutionClock {
                public function now(): DateTimeImmutable
                {
                    return new DateTimeImmutable('2026-07-28T10:00:00Z');
                }
            },
            new class implements ReportTransitionAudit {
                public function append(string $eventId, string $eventType, ReportExecutionContext $context, array $subject, DateTimeImmutable $occurredAt): void {}
            },
            new ReportExportHydrator,
            new class implements ReportDispatchIntentStore {
                public function addRunIntent(string $runId, int $organizationId, string $eventKey, DateTimeImmutable $occurredAt): void {}
                public function addExportIntent(string $exportId, int $organizationId, string $eventKey, DateTimeImmutable $occurredAt): void {}
                public function claimDue(int $limit, DateTimeImmutable $now, DateTimeImmutable $leasedUntil, string $leaseToken): array { return []; }
                public function markPublished(string $intentId, string $leaseToken, DateTimeImmutable $occurredAt): void {}
                public function markPublicationFailed(string $intentId, string $leaseToken, ReportErrorCode $errorCode, DateTimeImmutable $occurredAt, DateTimeImmutable $nextAttemptAt): void {}
                public function reclaimExpiredLeases(int $limit, DateTimeImmutable $occurredAt): int { return 0; }
            },
            3600,
            1000,
        );
    }

    private function exportRecord(array $attributes): ReportExportRecord
    {
        $record = new ReportExportRecord;
        $record->setRawAttributes($attributes, true);

        return $record;
    }

    private function runRecord(array $attributes): ReportRunRecord
    {
        $record = new ReportRunRecord;
        $record->setRawAttributes($attributes, true);

        return $record;
    }

    private function identityAttributes(string $prefix): array
    {
        $attributes = [];
        foreach ([
            'report_code', 'definition_hash', 'query_hash', 'source_hash', 'result_hash',
            'scope_timezone', 'snapshot_kind', 'snapshot_id', 'snapshot_classification',
            'snapshot_seal_key_id', 'snapshot_seal_algorithm', 'snapshot_sealed_payload_hash',
            'snapshot_seal_signature', 'data_classification', 'contract_version', 'formula_version',
            'source_schema_version', 'renderer_version',
        ] as $key) {
            $attributes[$key] = "{$prefix}-{$key}";
        }

        $attributes['scope_holding_organization_ids'] = [1, 2];
        $attributes['scope_project_ids'] = [3];
        $attributes['scope_resources'] = json_encode([['kind' => 'project', 'id' => 3, 'project_id' => 3]], JSON_THROW_ON_ERROR);
        $attributes['snapshot_generated_at'] = '2026-07-28 10:00:00.000000+00';
        $attributes['snapshot_stale_at'] = '2026-07-28 11:00:00.000000+00';
        $attributes['snapshot_watermarks'] = json_encode([['source' => "{$prefix}-source", 'watermark' => '1']], JSON_THROW_ON_ERROR);
        $attributes['snapshot_sealed_at'] = '2026-07-28 10:01:00.000000+00';
        $attributes['sensitive_column_ids'] = ['amount'];
        $attributes['audit_column_ids'] = ['status'];
        $attributes['totals_sensitive'] = true;
        $attributes['totals_audit'] = false;
        $attributes['provenance_audit'] = true;

        return $attributes;
    }
}
