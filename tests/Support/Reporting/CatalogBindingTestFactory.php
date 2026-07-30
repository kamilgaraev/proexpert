<?php

declare(strict_types=1);

namespace Tests\Support\Reporting;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportConformanceEvidenceRepository;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDataProvider;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionReadinessProbe;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDrillDownProvider;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportRowQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCursor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBinding;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionConformanceEvidence;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownInput;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFormulaConformanceEvidence;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProgress;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceConformanceEvidence;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use DateTimeImmutable;
use LogicException;
use ReflectionClass;

final class CatalogBindingTestFactory
{
    public static function binding(
        ReportDefinition $definition,
        ?ReportDefinitionReadinessProbe $probe = null,
        ?Sha256Hash $definitionHash = null,
        ?string $contractVersion = null,
        ?string $code = null,
    ): ReportDefinitionBinding {
        return new ReportDefinitionBinding(
            $code ?? $definition->code,
            $definitionHash ?? $definition->definitionHash,
            $contractVersion ?? $definition->contractVersion,
            new CatalogTestDataProvider,
            new CatalogTestRowQuery,
            new CatalogTestDrillDownProvider,
            $probe,
        );
    }

    public static function evidence(
        ReportDefinition $definition,
        ReportDefinitionBinding $binding,
        bool $passed = true,
        array $componentHashes = [],
        ?Sha256Hash $fixtureHash = null,
    ): ReportDefinitionConformanceEvidence {
        if ($componentHashes === []) {
            foreach ([
                $binding->dataProvider::class,
                $binding->rowQuery::class,
                $binding->drillDownProvider::class,
            ] as $class) {
                $file = (new ReflectionClass($class))->getFileName();
                if (! is_string($file)) {
                    throw new LogicException('test_provider_source_unavailable');
                }
                $componentHashes[$class] = new Sha256Hash(
                    (string) hash_file('sha256', $file),
                );
            }
        }

        $sourceCode = $passed
            ? 'source.identity.passed'
            : 'source.identity.failed';
        $formulaCode = $passed
            ? 'formula.identity.passed'
            : 'formula.identity.failed';

        return new ReportDefinitionConformanceEvidence(
            $definition->code,
            $definition->definitionHash,
            $definition->contractVersion,
            $definition->sourceSchemaVersion,
            $fixtureHash ?? new Sha256Hash(hash('sha256', $definition->code)),
            new ReportSourceConformanceEvidence(
                new Sha256Hash(str_repeat('1', 64)),
                'snapshot',
                'snapshot-1',
                1,
                new Sha256Hash(str_repeat('2', 64)),
                $passed,
                [$sourceCode],
            ),
            new ReportFormulaConformanceEvidence(
                $definition->formulaVersion,
                new Sha256Hash(str_repeat('3', 64)),
                $passed,
                [$formulaCode],
            ),
            $componentHashes,
            2,
            $passed ? 'passed' : 'failed',
            str_repeat('a', 40),
            new DateTimeImmutable('2026-07-30T00:00:00+00:00'),
        );
    }
}

final class RecordingReportConformanceEvidenceRepository implements ReportConformanceEvidenceRepository
{
    public array $gets = [];

    public function __construct(
        private ReportDefinitionConformanceEvidence $evidence,
    ) {}

    public function get(
        string $code,
        Sha256Hash $definitionHash,
        Sha256Hash $fixtureHash,
    ): ReportDefinitionConformanceEvidence {
        $this->gets[] = [$code, $definitionHash, $fixtureHash];

        return $this->evidence;
    }

    public function put(ReportDefinitionConformanceEvidence $evidence): void
    {
        $this->evidence = $evidence;
    }
}

final class CatalogTestDataProvider implements ReportDataProvider
{
    public function materialize(
        ReportExecutionContext $context,
        ReportQuery $query,
        ReportProgress $progress,
    ): ReportSnapshotRef {
        throw new LogicException('not_executed');
    }

    public function result(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
    ): ReportResult {
        throw new LogicException('not_executed');
    }
}

final class CatalogTestRowQuery implements ReportRowQuery
{
    public function page(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        ReportWindowSort $sort,
        ?ReportCursor $cursor,
        int $limit,
    ): ReportPage {
        throw new LogicException('not_executed');
    }

    public function cursor(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        ReportWindowSort $sort,
        int $chunkSize,
    ): iterable {
        throw new LogicException('not_executed');
    }
}

final class CatalogTestDrillDownProvider implements ReportDrillDownProvider
{
    public function drillDown(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        ReportDrillDownInput $input,
    ): ReportDrillDownResult {
        throw new LogicException('not_executed');
    }
}
