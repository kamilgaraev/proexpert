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
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadinessBuiltinPublishedReport;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadinessCandidateContract;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadinessProvider;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadinessPublishedRuntimeBindingRegistrar;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadinessQueryService;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadinessReportBindingFactory;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class PayrollReadinessPublishedRuntimeBindingRegistrarTest extends TestCase
{
    public function test_registers_existing_provider_query_and_signed_drill_contract(): void
    {
        $contract = new PayrollReadinessCandidateContract;
        $definition = (new PayrollReadinessBuiltinPublishedReport($contract))->definition();
        $provider = (new ReflectionClass(PayrollReadinessProvider::class))->newInstanceWithoutConstructor();
        $query = (new ReflectionClass(PayrollReadinessQueryService::class))->newInstanceWithoutConstructor();
        $assembler = new PayrollReadinessCapturingBindingAssembler;
        (new PayrollReadinessPublishedRuntimeBindingRegistrar(
            new PayrollReadinessPublishedRegistry($definition),
            new PayrollReadinessReportBindingFactory($provider, $query, $contract),
        ))->register($assembler);

        $binding = $assembler->bindings[PayrollReadinessCandidateContract::CODE];
        self::assertSame($provider, $binding->dataProvider);
        self::assertSame($query, $binding->rowQuery);
        self::assertSame($query, $binding->drillDownProvider);
        self::assertSame(['drill' => 'source_refs'], $query->drillDownTokenColumns());
        self::assertSame(['workforce.audit.view'], $definition->payload()->permissionPolicy->auditPermissions);
    }
}

final class PayrollReadinessCapturingBindingAssembler implements ReportDefinitionBindingAssembler
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

final readonly class PayrollReadinessPublishedRegistry implements ReportDefinitionRegistry
{
    public function __construct(private PublishedReportDefinition $definition) {}

    public function published(string $code): PublishedReportDefinition
    {
        return $code === PayrollReadinessCandidateContract::CODE ? $this->definition : throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND);
    }

    public function publishedCodes(): array
    {
        return [PayrollReadinessCandidateContract::CODE];
    }

    public function manifestSha256(): Sha256Hash
    {
        return new Sha256Hash(str_repeat('a', 64));
    }
}
