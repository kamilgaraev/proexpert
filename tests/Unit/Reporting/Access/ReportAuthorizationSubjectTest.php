<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Access;

use App\BusinessModules\Core\Reporting\Application\Access\ReportAuthorizationSubject;
use App\BusinessModules\Core\Reporting\Application\Dispatch\ReportDispatchAggregate;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\Reporting\ReportDefinitionBuilder;

final class ReportAuthorizationSubjectTest extends TestCase
{
    private const RUN_ID = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

    private const EXPORT_ID = '01ARZ3NDEKTSV4RRFFQ69G5FAW';

    public function test_contract_is_closed_and_readonly(): void
    {
        $reflection = new ReflectionClass(ReportAuthorizationSubject::class);

        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->isReadOnly());
        self::assertSame(
            [
                'aggregateKind' => ReportDispatchAggregate::class,
                'aggregateId' => 'string',
                'definition' => ReportDefinition::class,
                'scope' => ReportScope::class,
                'snapshot' => '?'.ReportSnapshotRef::class,
                'parentRunId' => '?string',
                'artifactIdentityHash' => '?'.Sha256Hash::class,
                'exportIdentityHash' => '?'.Sha256Hash::class,
                'exportFormat' => '?string',
            ],
            array_reduce(
                $reflection->getConstructor()?->getParameters() ?? [],
                static function (array $types, $parameter): array {
                    $types[$parameter->getName()] = (string) $parameter->getType();

                    return $types;
                },
                [],
            ),
        );
    }

    public function test_run_accepts_only_run_shape_and_exact_snapshot_scope(): void
    {
        $scope = $this->scope();
        $definition = $this->definition();
        $subject = new ReportAuthorizationSubject(
            ReportDispatchAggregate::RUN,
            self::RUN_ID,
            $definition,
            $scope,
            $this->snapshot($scope, $definition),
            null,
            null,
        );

        self::assertSame(self::RUN_ID, $subject->aggregateId);
        self::assertSame($scope, $subject->scope);
        self::assertSame($definition, $subject->definition);
    }

    public function test_export_accepts_only_complete_export_shape(): void
    {
        $scope = $this->scope();
        $definition = $this->definition();
        $artifactHash = new Sha256Hash(str_repeat('d', 64));
        $subject = new ReportAuthorizationSubject(
            ReportDispatchAggregate::EXPORT,
            self::EXPORT_ID,
            $definition,
            $scope,
            $this->snapshot($scope, $definition),
            self::RUN_ID,
            $artifactHash,
            null,
            'csv',
        );

        self::assertSame(self::RUN_ID, $subject->parentRunId);
        self::assertSame($artifactHash, $subject->artifactIdentityHash);
    }

    public function test_export_before_ready_accepts_absent_artifact_identity(): void
    {
        $scope = $this->scope();
        $definition = $this->definition();
        $subject = new ReportAuthorizationSubject(
            ReportDispatchAggregate::EXPORT,
            self::EXPORT_ID,
            $definition,
            $scope,
            $this->snapshot($scope, $definition),
            self::RUN_ID,
            null,
            null,
            'csv',
        );

        self::assertNull($subject->artifactIdentityHash);
    }

    public function test_persisted_export_format_participates_in_subject_identity(): void
    {
        $scope = $this->scope();
        $definition = (new ReportDefinitionBuilder)->formats(['xlsx', 'pdf'])->payload();
        $snapshot = $this->snapshot($scope, $definition);

        $excel = new ReportAuthorizationSubject(
            ReportDispatchAggregate::EXPORT,
            self::EXPORT_ID,
            $definition,
            $scope,
            $snapshot,
            self::RUN_ID,
            null,
            null,
            'xlsx',
        );
        $pdf = new ReportAuthorizationSubject(
            ReportDispatchAggregate::EXPORT,
            self::EXPORT_ID,
            $definition,
            $scope,
            $snapshot,
            self::RUN_ID,
            null,
            null,
            'pdf',
        );

        self::assertNotSame($excel->canonicalFingerprint(), $pdf->canonicalFingerprint());
    }

    #[DataProvider('invalidShapeProvider')]
    public function test_cross_field_mismatch_fails_closed(
        ReportDispatchAggregate $kind,
        bool $snapshot,
        ?string $parentRunId,
        bool $artifactHash,
    ): void {
        $scope = $this->scope();
        $definition = $this->definition();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_authorization_subject_invalid');

        new ReportAuthorizationSubject(
            $kind,
            $kind === ReportDispatchAggregate::RUN ? self::RUN_ID : self::EXPORT_ID,
            $definition,
            $scope,
            $snapshot ? $this->snapshot($scope, $definition) : null,
            $parentRunId,
            $artifactHash ? new Sha256Hash(str_repeat('e', 64)) : null,
        );
    }

    public function test_definition_and_snapshot_identity_mismatch_fails_closed(): void
    {
        $scope = $this->scope();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_authorization_subject_invalid');

        new ReportAuthorizationSubject(
            ReportDispatchAggregate::RUN,
            self::RUN_ID,
            $this->definition(),
            $scope,
            $this->snapshot(
                $scope,
                (new ReportDefinitionBuilder)
                    ->definitionHash(new Sha256Hash(str_repeat('f', 64)))
                    ->payload(),
            ),
            null,
            null,
        );
    }

    public function test_snapshot_scope_mismatch_fails_closed(): void
    {
        $definition = $this->definition();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_authorization_subject_invalid');

        new ReportAuthorizationSubject(
            ReportDispatchAggregate::RUN,
            self::RUN_ID,
            $definition,
            $this->scope(),
            $this->snapshot(new ReportScope(2, [2], [], [], new DateTimeZone('UTC')), $definition),
            null,
            null,
        );
    }

    public static function invalidShapeProvider(): array
    {
        return [
            'run cannot have parent' => [ReportDispatchAggregate::RUN, false, self::RUN_ID, false],
            'run cannot have artifact' => [ReportDispatchAggregate::RUN, false, null, true],
            'export requires snapshot' => [ReportDispatchAggregate::EXPORT, false, self::RUN_ID, true],
            'export requires parent' => [ReportDispatchAggregate::EXPORT, true, null, true],
        ];
    }

    private function definition(): ReportDefinition
    {
        return (new ReportDefinitionBuilder)->payload();
    }

    private function scope(): ReportScope
    {
        return new ReportScope(1, [1], [], [], new DateTimeZone('UTC'));
    }

    private function snapshot(ReportScope $scope, ReportDefinition $definition): ReportSnapshotRef
    {
        return new ReportSnapshotRef(
            'report',
            'snapshot',
            $scope,
            $definition->definitionHash,
            $definition->formulaVersion,
            new Sha256Hash(str_repeat('b', 64)),
            new DateTimeImmutable('2026-07-29T00:00:00.000000Z'),
            null,
            [],
            ReportSnapshotClassification::OPERATIONAL,
            null,
        );
    }
}
