<?php

declare(strict_types=1);

namespace Tests\Architecture\Reporting;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\CurrentReportScopeAuthorizer;
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
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

final class ReportPortSignatureTest extends TestCase
{
    #[Test]
    public function every_owner_port_method_has_the_exact_public_signature(): void
    {
        $this->assertPort(CurrentReportScopeAuthorizer::class, [
            'authorizeCatalog' => [['actorId', 'int'], ['organizationId', 'int'], ['timezone', 'DateTimeZone'], ['targets', 'array'], 'App\\BusinessModules\\Core\\Reporting\\Application\\Access\\ReportCatalogAuthorization'],
            'authorizeForOrganization' => [['actorId', 'int'], ['organizationId', 'int'], ['timezone', 'DateTimeZone'], ['target', 'App\\BusinessModules\\Core\\Reporting\\Application\\Execution\\CurrentReportAuthorizationTarget'], 'App\\BusinessModules\\Core\\Reporting\\Application\\Execution\\CurrentReportAuthorization'],
            'authorizeExact' => [['actorId', 'int'], ['requestedScope', 'App\\BusinessModules\\Core\\Reporting\\Domain\\DTO\\ReportScope'], ['target', 'App\\BusinessModules\\Core\\Reporting\\Application\\Execution\\CurrentReportAuthorizationTarget'], 'App\\BusinessModules\\Core\\Reporting\\Application\\Execution\\CurrentReportAuthorization'],
            'authorizeExactMany' => [['actorId', 'int'], ['requestedScope', 'App\\BusinessModules\\Core\\Reporting\\Domain\\DTO\\ReportScope'], ['targets', 'array'], 'array'],
        ]);
        $this->assertPort(ReportDataProvider::class, [
            'materialize' => [['context', 'App\\BusinessModules\\Core\\Reporting\\Domain\\DTO\\ReportExecutionContext'], ['query', 'App\\BusinessModules\\Core\\Reporting\\Domain\\DTO\\ReportQuery'], ['progress', 'App\\BusinessModules\\Core\\Reporting\\Domain\\DTO\\ReportProgress'], 'App\\BusinessModules\\Core\\Reporting\\Domain\\DTO\\ReportSnapshotRef'],
            'result' => [['context', 'App\\BusinessModules\\Core\\Reporting\\Domain\\DTO\\ReportExecutionContext'], ['snapshot', 'App\\BusinessModules\\Core\\Reporting\\Domain\\DTO\\ReportSnapshotRef'], 'App\\BusinessModules\\Core\\Reporting\\Domain\\DTO\\ReportResult'],
        ]);
        $this->assertPort(ReportRowQuery::class, [
            'page' => [['context', 'App\\BusinessModules\\Core\\Reporting\\Domain\\DTO\\ReportExecutionContext'], ['snapshot', 'App\\BusinessModules\\Core\\Reporting\\Domain\\DTO\\ReportSnapshotRef'], ['sort', 'App\\BusinessModules\\Core\\Reporting\\Domain\\DTO\\ReportWindowSort'], ['cursor', 'App\\BusinessModules\\Core\\Reporting\\Domain\\DTO\\ReportCursor', true], ['limit', 'int'], 'App\\BusinessModules\\Core\\Reporting\\Domain\\DTO\\ReportPage'],
            'cursor' => [['context', 'App\\BusinessModules\\Core\\Reporting\\Domain\\DTO\\ReportExecutionContext'], ['snapshot', 'App\\BusinessModules\\Core\\Reporting\\Domain\\DTO\\ReportSnapshotRef'], ['sort', 'App\\BusinessModules\\Core\\Reporting\\Domain\\DTO\\ReportWindowSort'], ['chunkSize', 'int'], 'iterable'],
        ]);
        $this->assertPort(ReportDrillDownProvider::class, [
            'drillDown' => [['context', 'App\\BusinessModules\\Core\\Reporting\\Domain\\DTO\\ReportExecutionContext'], ['snapshot', 'App\\BusinessModules\\Core\\Reporting\\Domain\\DTO\\ReportSnapshotRef'], ['input', 'App\\BusinessModules\\Core\\Reporting\\Domain\\DTO\\ReportDrillDownInput'], 'App\\BusinessModules\\Core\\Reporting\\Domain\\DTO\\ReportDrillDownResult'],
        ]);
        $this->assertPort(ReportDefinitionReadinessProbe::class, [
            'supports' => [['definition', 'App\\BusinessModules\\Core\\Reporting\\Domain\\DTO\\ReportDefinition'], 'bool'],
        ]);
        $this->assertPort(ReportDefinitionRegistry::class, [
            'published' => [['code', 'string'], PublishedReportDefinition::class],
            'publishedCodes' => ['array'],
            'manifestSha256' => [Sha256Hash::class],
        ]);
        $this->assertPort(CandidateReportDefinitionRegistry::class, [
            'candidate' => [['code', 'string'], CandidateReportDefinition::class],
            'candidateCodes' => ['array'],
        ]);
        $this->assertPort(ReportDefinitionBindingAssembler::class, [
            'register' => [['binding', 'App\\BusinessModules\\Core\\Reporting\\Domain\\DTO\\ReportDefinitionBinding'], 'void'],
            'assemble' => [['registry', ReportDefinitionRegistry::class], 'App\\BusinessModules\\Core\\Reporting\\Domain\\DTO\\ReportDefinitionBindingMap'],
        ]);
        $this->assertPort(ReportDefinitionCandidateValidator::class, [
            'validate' => [['registry', CandidateReportDefinitionRegistry::class], ['bindings', 'iterable'], 'App\\BusinessModules\\Core\\Reporting\\Domain\\DTO\\ReportCandidateValidationResult'],
        ]);
    }

    private function assertPort(string $port, array $methods): void
    {
        $publicMethods = (new \ReflectionClass($port))->getMethods(ReflectionMethod::IS_PUBLIC);
        self::assertSame(array_keys($methods), array_map(static fn (ReflectionMethod $method): string => $method->getName(), $publicMethods));

        foreach ($methods as $name => $signature) {
            $method = new ReflectionMethod($port, $name);
            self::assertTrue($method->isPublic());
            $returnType = array_pop($signature);
            self::assertSame($returnType, $this->typeName($method->getReturnType()));
            self::assertFalse($method->getReturnType()?->allowsNull() ?? true);
            self::assertSame(count($signature), $method->getNumberOfParameters());

            foreach ($signature as $index => $expected) {
                $this->assertParameter($method->getParameters()[$index], $expected[0], $expected[1], $expected[2] ?? false);
            }
        }
    }

    private function assertParameter(ReflectionParameter $parameter, string $name, string $type, bool $nullable): void
    {
        self::assertSame($name, $parameter->getName());
        self::assertSame($type, $this->typeName($parameter->getType()));
        self::assertSame($nullable, $parameter->allowsNull());
        self::assertFalse($parameter->isOptional());
        self::assertFalse($parameter->isDefaultValueAvailable());
    }

    private function typeName(?ReflectionNamedType $type): ?string
    {
        return $type?->getName();
    }
}
