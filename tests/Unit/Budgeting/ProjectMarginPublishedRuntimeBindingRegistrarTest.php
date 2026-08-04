<?php

declare(strict_types=1);

namespace Tests\Unit\Budgeting;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionBindingAssembler;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBinding;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBindingMap;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectMarginBuiltinPublishedReport;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectMarginCandidateContract;
use App\BusinessModules\Features\Budgeting\Services\ProjectMarginPublishedRuntimeBindingRegistrar;
use App\BusinessModules\Features\Budgeting\Services\ProjectMarginReportBindingFactory;
use App\BusinessModules\Features\Budgeting\Services\ProjectMarginReportSourceSnapshotAdapter;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ProjectMarginPublishedRuntimeBindingRegistrarTest extends TestCase
{
    public function test_registers_the_sealed_snapshot_provider_for_the_published_definition(): void
    {
        $definition = (new ProjectMarginBuiltinPublishedReport(new ProjectMarginCandidateContract))->definition();
        $adapter = (new ReflectionClass(ProjectMarginReportSourceSnapshotAdapter::class))->newInstanceWithoutConstructor();
        $assembler = new ProjectMarginCapturingBindingAssembler;
        $registrar = new ProjectMarginPublishedRuntimeBindingRegistrar(
            new ProjectMarginPublishedRegistry($definition),
            new ProjectMarginReportBindingFactory($adapter, new ProjectMarginCandidateContract),
        );

        $registrar->register($assembler);

        $binding = $assembler->bindings[ProjectMarginCandidateContract::CODE];
        self::assertSame($adapter, $binding->dataProvider);
        self::assertSame($adapter, $binding->rowQuery);
        self::assertSame($adapter, $binding->drillDownProvider);
    }

    public function test_skips_registration_when_definition_is_not_published(): void
    {
        $assembler = new ProjectMarginCapturingBindingAssembler;
        $registrar = new ProjectMarginPublishedRuntimeBindingRegistrar(
            new ProjectMarginUnpublishedRegistry,
            new ProjectMarginReportBindingFactory(
                (new ReflectionClass(ProjectMarginReportSourceSnapshotAdapter::class))->newInstanceWithoutConstructor(),
                new ProjectMarginCandidateContract,
            ),
        );

        $registrar->register($assembler);

        self::assertSame([], $assembler->bindings);
    }
}

final class ProjectMarginCapturingBindingAssembler implements ReportDefinitionBindingAssembler
{
    /** @var array<string, ReportDefinitionBinding> */
    public array $bindings = [];

    public function register(ReportDefinitionBinding $binding): void
    {
        $this->bindings[$binding->code] = $binding;
    }

    public function assemble(ReportDefinitionRegistry $registry): ReportDefinitionBindingMap
    {
        throw new LogicException('not_used');
    }
}

final readonly class ProjectMarginPublishedRegistry implements ReportDefinitionRegistry
{
    public function __construct(private PublishedReportDefinition $definition) {}

    public function published(string $code): PublishedReportDefinition
    {
        return $code === ProjectMarginCandidateContract::CODE
            ? $this->definition
            : throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND);
    }

    public function publishedCodes(): array
    {
        return [ProjectMarginCandidateContract::CODE];
    }

    public function manifestSha256(): Sha256Hash
    {
        return new Sha256Hash(str_repeat('a', 64));
    }
}

final class ProjectMarginUnpublishedRegistry implements ReportDefinitionRegistry
{
    public function published(string $code): PublishedReportDefinition
    {
        throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND);
    }

    public function publishedCodes(): array
    {
        return [];
    }

    public function manifestSha256(): Sha256Hash
    {
        return new Sha256Hash(str_repeat('a', 64));
    }
}
