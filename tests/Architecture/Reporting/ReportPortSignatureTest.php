<?php

declare(strict_types=1);

namespace Tests\Architecture\Reporting;

use App\BusinessModules\Core\Reporting\Domain\Contracts\CandidateReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDataProvider;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionBindingAssembler;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionCandidateValidator;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionReadinessProbe;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDrillDownProvider;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportRowQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\CandidateReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ReportPortSignatureTest extends TestCase
{
    #[Test]
    public function owner_ports_expose_the_exact_contracts(): void
    {
        $this->assertSignature(ReportDataProvider::class, 'materialize', 3, 'App\\BusinessModules\\Core\\Reporting\\Domain\\DTO\\ReportSnapshotRef');
        $this->assertSignature(ReportDataProvider::class, 'result', 2, 'App\\BusinessModules\\Core\\Reporting\\Domain\\DTO\\ReportResult');
        $this->assertSignature(ReportRowQuery::class, 'page', 5, 'App\\BusinessModules\\Core\\Reporting\\Domain\\DTO\\ReportPage');
        $this->assertSignature(ReportRowQuery::class, 'cursor', 4, 'iterable');
        $this->assertSignature(ReportDrillDownProvider::class, 'drillDown', 3, 'App\\BusinessModules\\Core\\Reporting\\Domain\\DTO\\ReportDrillDownResult');
        $this->assertSignature(ReportDefinitionReadinessProbe::class, 'supports', 1, 'bool');
        $this->assertSignature(ReportDefinitionRegistry::class, 'published', 1, PublishedReportDefinition::class);
        $this->assertSignature(CandidateReportDefinitionRegistry::class, 'candidate', 1, CandidateReportDefinition::class);
    }

    #[Test]
    public function binding_lifecycle_ports_use_nominal_registries(): void
    {
        $this->assertSignature(ReportDefinitionBindingAssembler::class, 'register', 1, 'void');
        $this->assertSignature(ReportDefinitionBindingAssembler::class, 'assemble', 1, 'App\\BusinessModules\\Core\\Reporting\\Domain\\DTO\\ReportDefinitionBindingMap');
        $this->assertSignature(ReportDefinitionCandidateValidator::class, 'validate', 2, 'App\\BusinessModules\\Core\\Reporting\\Domain\\DTO\\ReportCandidateValidationResult');
        self::assertSame(ReportDefinitionRegistry::class, (new ReflectionMethod(ReportDefinitionBindingAssembler::class, 'assemble'))->getParameters()[0]->getType()?->getName());
        self::assertSame(CandidateReportDefinitionRegistry::class, (new ReflectionMethod(ReportDefinitionCandidateValidator::class, 'validate'))->getParameters()[0]->getType()?->getName());
    }

    private function assertSignature(string $class, string $method, int $arity, string $returnType): void
    {
        $reflection = new ReflectionMethod($class, $method);

        self::assertSame($arity, $reflection->getNumberOfParameters());
        self::assertSame($returnType, $reflection->getReturnType()?->getName());
    }
}
