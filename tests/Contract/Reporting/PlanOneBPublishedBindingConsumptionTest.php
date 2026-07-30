<?php

declare(strict_types=1);

namespace Tests\Contract\Reporting;

use App\BusinessModules\Core\Reporting\Application\Actions\Handlers\GetReportDrillDownHandler;
use App\BusinessModules\Core\Reporting\Application\Actions\Handlers\GetReportRowsHandler;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportRunCoordinator;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportExportCoordinator;
use App\BusinessModules\Core\Reporting\Domain\Contracts\CandidateReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionBindingAssembler;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBindingMap;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

final class PlanOneBPublishedBindingConsumptionTest extends TestCase
{
    public function test_plan_one_b_consumers_use_plan_one_a_registry_and_binding_contracts(): void
    {
        $expected = [
            ReportRunCoordinator::class => [ReportDefinitionRegistry::class],
            GetReportRowsHandler::class => [
                ReportDefinitionRegistry::class,
                ReportDefinitionBindingAssembler::class,
            ],
            GetReportDrillDownHandler::class => [
                ReportDefinitionRegistry::class,
                ReportDefinitionBindingAssembler::class,
            ],
            ReportExportCoordinator::class => [
                ReportDefinitionRegistry::class,
                ReportDefinitionBindingAssembler::class,
            ],
        ];

        foreach ($expected as $class => $contracts) {
            $constructor = (new ReflectionClass($class))->getConstructor();
            $types = [];
            foreach ($constructor?->getParameters() ?? [] as $parameter) {
                $type = $parameter->getType();
                if ($type instanceof ReflectionNamedType) {
                    $types[] = $type->getName();
                }
            }
            foreach ($contracts as $contract) {
                self::assertContains($contract, $types, $class);
            }
            self::assertNotContains(CandidateReportDefinitionRegistry::class, $types, $class);
        }
    }

    public function test_plan_one_b_has_no_execution_resolver_or_adapter_map(): void
    {
        $root = dirname(__DIR__, 3).'/app/BusinessModules/Core/Reporting';
        $symbols = [
            $root.'/Application/Actions/Handlers/GetReportRowsHandler.php',
            $root.'/Application/Actions/Handlers/GetReportDrillDownHandler.php',
            $root.'/Application/Execution/ReportRunCoordinator.php',
            $root.'/Application/Exports/ReportExportCoordinator.php',
        ];

        foreach ($symbols as $file) {
            $source = (string) file_get_contents($file);
            self::assertStringNotContainsString('ExecutionResolver', $source);
            self::assertStringNotContainsString('AdapterBindingMap', $source);
            self::assertStringNotContainsString('CandidateReportDefinitionRegistry', $source);
        }
    }

    public function test_plan_one_b_binding_contract_publishes_the_exact_map_type(): void
    {
        $method = (new ReflectionClass(ReportDefinitionBindingAssembler::class))
            ->getMethod('assemble');
        $return = $method->getReturnType();

        self::assertInstanceOf(ReflectionNamedType::class, $return);
        self::assertSame(ReportDefinitionBindingMap::class, $return->getName());
        self::assertSame(
            ReportDefinitionRegistry::class,
            $method->getParameters()[0]->getType()?->getName(),
        );
    }
}
