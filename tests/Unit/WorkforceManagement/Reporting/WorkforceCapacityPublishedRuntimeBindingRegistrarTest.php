<?php

declare(strict_types=1);

namespace Tests\Unit\WorkforceManagement\Reporting;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionBindingAssembler;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBinding;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBindingMap;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Features\WorkforceManagement\Reporting\WorkforceCapacityBuiltinPublishedReport;
use App\BusinessModules\Features\WorkforceManagement\Reporting\WorkforceCapacityCandidateContract;
use App\BusinessModules\Features\WorkforceManagement\Reporting\WorkforceCapacityProvider;
use App\BusinessModules\Features\WorkforceManagement\Reporting\WorkforceCapacityPublishedRuntimeBindingRegistrar;
use App\BusinessModules\Features\WorkforceManagement\Reporting\WorkforceCapacityReportBindingFactory;
use App\BusinessModules\Features\WorkforceManagement\Reporting\WorkforceReportQueryService;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class WorkforceCapacityPublishedRuntimeBindingRegistrarTest extends TestCase
{
    public function test_registers_real_provider_query_sensitive_policy_and_signed_drill(): void
    {
        $contract = new WorkforceCapacityCandidateContract;
        $definition = (new WorkforceCapacityBuiltinPublishedReport($contract))->definition();
        $provider = (new ReflectionClass(WorkforceCapacityProvider::class))->newInstanceWithoutConstructor();
        $query = (new ReflectionClass(WorkforceReportQueryService::class))->newInstanceWithoutConstructor();
        $assembler = new WorkforceCapacityCapturingBindingAssembler;
        (new WorkforceCapacityPublishedRuntimeBindingRegistrar(
            new WorkforceCapacityPublishedRegistry($definition),
            new WorkforceCapacityReportBindingFactory($provider, $query, $contract),
        ))->register($assembler);

        $binding = $assembler->bindings[WorkforceCapacityCandidateContract::CODE];
        self::assertSame($provider, $binding->dataProvider);
        self::assertSame($query, $binding->rowQuery);
        self::assertSame($query, $binding->drillDownProvider);
        self::assertSame(['drill' => 'source_refs'], $query->drillDownTokenColumns());
        self::assertSame(['workforce.audit.view'], $definition->payload()->permissionPolicy->sensitivePermissions);
    }
}

final class WorkforceCapacityCapturingBindingAssembler implements ReportDefinitionBindingAssembler
{
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

final readonly class WorkforceCapacityPublishedRegistry implements ReportDefinitionRegistry
{
    public function __construct(private PublishedReportDefinition $definition) {}

    public function published(string $code): PublishedReportDefinition
    {
        return $code === WorkforceCapacityCandidateContract::CODE
            ? $this->definition
            : throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND);
    }

    public function publishedCodes(): array
    {
        return [WorkforceCapacityCandidateContract::CODE];
    }

    public function manifestSha256(): Sha256Hash
    {
        return new Sha256Hash(str_repeat('b', 64));
    }
}
