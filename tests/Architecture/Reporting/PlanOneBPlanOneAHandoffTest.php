<?php

declare(strict_types=1);

namespace Tests\Architecture\Reporting;

use App\BusinessModules\Core\Reporting\Application\Evidence\PlanOneACompletionRef;
use App\BusinessModules\Core\Reporting\Application\Evidence\PlanOneACompletionVerifier;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDataProvider;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDrillDownProvider;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportRowQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCursor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDownloadLink;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExport;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProgress;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportRun;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportExportStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportFreshnessStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportOperation;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportRunStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotIdentityViolationReason;
use App\BusinessModules\Core\Reporting\Domain\Exceptions\ReportSnapshotIdentityViolation;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

final class PlanOneBPlanOneAHandoffTest extends TestCase
{
    public function test_verifier_has_exact_required_five_argument_signature(): void
    {
        $method = new ReflectionMethod(PlanOneACompletionVerifier::class, 'assertReady');
        self::assertTrue($method->isPublic());
        self::assertSame(PlanOneACompletionRef::class, $this->typeName($method->getReturnType()));
        self::assertFalse($method->getReturnType()?->allowsNull() ?? true);
        self::assertSame(
            ['lock', 'completionSchema', 'completionArtifact', 'authorizationSchema', 'authorizationArtifact'],
            array_map(static fn (ReflectionParameter $parameter): string => $parameter->getName(), $method->getParameters()),
        );
        foreach ($method->getParameters() as $parameter) {
            self::assertSame('string', $this->typeName($parameter->getType()));
            self::assertFalse($parameter->allowsNull());
            self::assertFalse($parameter->isOptional());
            self::assertFalse($parameter->isDefaultValueAvailable());
        }
    }

    public function test_imported_plan_one_a_constructors_remain_exact(): void
    {
        $constructors = [
            ReportQuery::class => ['definition:App\\BusinessModules\\Core\\Reporting\\Domain\\DTO\\ReportDefinition', 'scope:App\\BusinessModules\\Core\\Reporting\\Domain\\DTO\\ReportScope', 'filters:App\\BusinessModules\\Core\\Reporting\\Domain\\DTO\\ReportFilterSet', 'comparison:array', 'asOf:DateTimeImmutable', 'locale:string'],
            ReportPage::class => ['rows:array', 'totals:array', 'freshness:App\\BusinessModules\\Core\\Reporting\\Domain\\Enums\\ReportFreshnessStatus', 'quality:App\\BusinessModules\\Core\\Reporting\\Domain\\DTO\\ReportQuality', 'nextCursor:?string', 'limit:int', 'hasMore:bool', 'sort:App\\BusinessModules\\Core\\Reporting\\Domain\\DTO\\ReportWindowSort'],
            ReportProgress::class => ['percent:int'],
            ReportRun::class => ['id:string', 'reportCode:string', 'status:App\\BusinessModules\\Core\\Reporting\\Domain\\Enums\\ReportRunStatus', 'definitionHash:App\\BusinessModules\\Core\\Reporting\\Domain\\ValueObjects\\Sha256Hash', 'contractVersion:string', 'formulaVersion:string', 'sourceSchemaVersion:string', 'rendererVersion:string', 'queryHash:App\\BusinessModules\\Core\\Reporting\\Domain\\ValueObjects\\Sha256Hash', 'sourceHash:?App\\BusinessModules\\Core\\Reporting\\Domain\\ValueObjects\\Sha256Hash', 'progress:int', 'rowCount:?int', 'resultMetadata:?App\\BusinessModules\\Core\\Reporting\\Domain\\DTO\\ReportResultMetadata', 'totals:array', 'freshness:?App\\BusinessModules\\Core\\Reporting\\Domain\\Enums\\ReportFreshnessStatus', 'quality:?App\\BusinessModules\\Core\\Reporting\\Domain\\DTO\\ReportQuality', 'provenance:?App\\BusinessModules\\Core\\Reporting\\Domain\\DTO\\ReportProvenance', 'createdAt:DateTimeImmutable', 'updatedAt:DateTimeImmutable', 'readyAt:?DateTimeImmutable', 'expiresAt:DateTimeImmutable', 'cancelRequestedAt:?DateTimeImmutable', 'httpDisposition:string', 'pollAfterMs:?int'],
            ReportExport::class => ['id:string', 'runId:string', 'status:App\\BusinessModules\\Core\\Reporting\\Domain\\Enums\\ReportExportStatus', 'exportHash:App\\BusinessModules\\Core\\Reporting\\Domain\\ValueObjects\\Sha256Hash', 'format:string', 'columns:array', 'sort:App\\BusinessModules\\Core\\Reporting\\Domain\\DTO\\ReportWindowSort', 'locale:string', 'timezone:DateTimeZone', 'artifactPath:?string', 'versionId:?string', 'etag:?string', 'checksum:?App\\BusinessModules\\Core\\Reporting\\Domain\\ValueObjects\\Sha256Hash', 'sizeBytes:?int', 'rowCount:?int', 'createdAt:DateTimeImmutable', 'updatedAt:DateTimeImmutable', 'readyAt:?DateTimeImmutable', 'expiresAt:DateTimeImmutable', 'cancelRequestedAt:?DateTimeImmutable', 'httpDisposition:string', 'pollAfterMs:?int'],
            ReportSnapshotRef::class => ['kind:string', 'id:string', 'scope:App\\BusinessModules\\Core\\Reporting\\Domain\\DTO\\ReportScope', 'definitionHash:App\\BusinessModules\\Core\\Reporting\\Domain\\ValueObjects\\Sha256Hash', 'formulaVersion:string', 'sourceHash:App\\BusinessModules\\Core\\Reporting\\Domain\\ValueObjects\\Sha256Hash', 'generatedAt:DateTimeImmutable', 'staleAt:?DateTimeImmutable', 'watermarks:array', 'classification:App\\BusinessModules\\Core\\Reporting\\Domain\\Enums\\ReportSnapshotClassification', 'seal:?App\\BusinessModules\\Core\\Reporting\\Domain\\DTO\\ReportSnapshotSeal'],
            ReportCursor::class => ['token:string', 'runId:string', 'queryHash:App\\BusinessModules\\Core\\Reporting\\Domain\\ValueObjects\\Sha256Hash', 'sourceHash:App\\BusinessModules\\Core\\Reporting\\Domain\\ValueObjects\\Sha256Hash', 'sort:App\\BusinessModules\\Core\\Reporting\\Domain\\DTO\\ReportWindowSort', 'expiresAt:DateTimeImmutable'],
            ReportDownloadLink::class => ['url:string', 'versionId:string', 'issuedAt:DateTimeImmutable', 'expiresAt:DateTimeImmutable'],
        ];

        foreach ($constructors as $class => $signature) {
            $constructor = (new ReflectionClass($class))->getConstructor();
            self::assertNotNull($constructor, $class);
            self::assertSame($signature, array_map($this->parameterSignature(...), $constructor->getParameters()), $class);
        }
    }

    public function test_imported_provider_ports_remain_exact(): void
    {
        $this->assertMethod(ReportDataProvider::class, 'materialize', ['context:App\\BusinessModules\\Core\\Reporting\\Domain\\DTO\\ReportExecutionContext', 'query:App\\BusinessModules\\Core\\Reporting\\Domain\\DTO\\ReportQuery', 'progress:App\\BusinessModules\\Core\\Reporting\\Domain\\DTO\\ReportProgress'], 'App\\BusinessModules\\Core\\Reporting\\Domain\\DTO\\ReportSnapshotRef');
        $this->assertMethod(ReportDataProvider::class, 'result', ['context:App\\BusinessModules\\Core\\Reporting\\Domain\\DTO\\ReportExecutionContext', 'snapshot:App\\BusinessModules\\Core\\Reporting\\Domain\\DTO\\ReportSnapshotRef'], 'App\\BusinessModules\\Core\\Reporting\\Domain\\DTO\\ReportResult');
        $this->assertMethod(ReportRowQuery::class, 'page', ['context:App\\BusinessModules\\Core\\Reporting\\Domain\\DTO\\ReportExecutionContext', 'snapshot:App\\BusinessModules\\Core\\Reporting\\Domain\\DTO\\ReportSnapshotRef', 'sort:App\\BusinessModules\\Core\\Reporting\\Domain\\DTO\\ReportWindowSort', 'cursor:?App\\BusinessModules\\Core\\Reporting\\Domain\\DTO\\ReportCursor', 'limit:int'], 'App\\BusinessModules\\Core\\Reporting\\Domain\\DTO\\ReportPage');
        $this->assertMethod(ReportRowQuery::class, 'cursor', ['context:App\\BusinessModules\\Core\\Reporting\\Domain\\DTO\\ReportExecutionContext', 'snapshot:App\\BusinessModules\\Core\\Reporting\\Domain\\DTO\\ReportSnapshotRef', 'sort:App\\BusinessModules\\Core\\Reporting\\Domain\\DTO\\ReportWindowSort', 'chunkSize:int'], 'iterable');
        $this->assertMethod(ReportDrillDownProvider::class, 'drillDown', ['context:App\\BusinessModules\\Core\\Reporting\\Domain\\DTO\\ReportExecutionContext', 'snapshot:App\\BusinessModules\\Core\\Reporting\\Domain\\DTO\\ReportSnapshotRef', 'request:App\\BusinessModules\\Core\\Reporting\\Domain\\DTO\\ReportDrillDownRequest'], 'App\\BusinessModules\\Core\\Reporting\\Domain\\DTO\\ReportDrillDownResult');
    }

    public function test_progress_advance_remains_one_integer_to_boolean(): void
    {
        $this->assertMethod(ReportProgress::class, 'advance', ['percent:int'], 'bool');
    }

    public function test_imported_enum_cases_remain_exact(): void
    {
        self::assertSame(['queued', 'materializing', 'ready', 'failed', 'cancelled', 'expired'], array_column(ReportRunStatus::cases(), 'value'));
        self::assertSame(['queued', 'running', 'uploading', 'ready', 'failed', 'cancelled', 'expired'], array_column(ReportExportStatus::cases(), 'value'));
        self::assertSame(['view', 'run', 'export', 'download', 'manage', 'view_sensitive', 'view_audit', 'drill_down'], array_column(ReportOperation::cases(), 'value'));
        self::assertSame(['asc', 'desc'], array_column(ReportSortDirection::cases(), 'value'));
        self::assertSame(['fresh', 'stale', 'partial', 'unavailable'], array_column(ReportFreshnessStatus::cases(), 'value'));
        self::assertSame(['complete', 'partial', 'invalid'], array_column(ReportQualityStatus::cases(), 'value'));
        self::assertSame(['invalid_kind', 'invalid_id', 'official_seal_required', 'operational_seal_forbidden', 'seal_time_invalid'], array_column(ReportSnapshotIdentityViolationReason::cases(), 'value'));
    }

    public function test_snapshot_identity_exception_remains_typed_and_message_independent(): void
    {
        $reflection = new ReflectionClass(ReportSnapshotIdentityViolation::class);
        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->getProperty('reason')->isReadOnly());

        foreach (ReportSnapshotIdentityViolationReason::cases() as $reason) {
            $exception = new ReportSnapshotIdentityViolation($reason);
            self::assertSame($reason, $exception->reason);
            self::assertSame('snapshot_identity_invalid', $exception->getMessage());
        }
    }

    private function assertMethod(string $class, string $name, array $parameters, string $return): void
    {
        $method = new ReflectionMethod($class, $name);
        self::assertTrue($method->isPublic());
        self::assertSame($parameters, array_map($this->parameterSignature(...), $method->getParameters()));
        self::assertSame($return, $this->typeName($method->getReturnType()));
        self::assertFalse($method->getReturnType()?->allowsNull() ?? true);
    }

    private function typeName(?ReflectionNamedType $type): ?string
    {
        return $type?->getName();
    }

    private function parameterSignature(ReflectionParameter $parameter): string
    {
        self::assertFalse($parameter->isOptional());
        self::assertFalse($parameter->isDefaultValueAvailable());

        return $parameter->getName().':'.($parameter->allowsNull() ? '?' : '').$this->typeName($parameter->getType());
    }
}
