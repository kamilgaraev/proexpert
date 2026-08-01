<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Publication;

use App\BusinessModules\Core\Reporting\Application\Publication\BudgetPlanFactReleaseBindingFactoryAdapter;
use App\BusinessModules\Core\Reporting\Application\Publication\BudgetPlanFactReleaseCandidateResolverAdapter;
use App\BusinessModules\Core\Reporting\Application\Publication\ProjectReportPublicationReleaseRequestRegistryFactory;
use App\BusinessModules\Features\Budgeting\Reporting\BudgetPlanFactCandidateContract;
use App\BusinessModules\Features\Budgeting\Reporting\BudgetPlanFactReleaseCandidateLayout;
use App\BusinessModules\Features\Budgeting\Reporting\BudgetPlanFactReleaseCandidateResolver;
use App\BusinessModules\Features\Budgeting\Services\BudgetPlanFactReportBindingFactory;
use App\BusinessModules\Features\Budgeting\Services\PlanFactReportSourceSnapshotAdapter;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\CiEvidence\ProcurementCycleReleaseCandidateResolver;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Services\ProcurementCycleReportBindingFactory;
use Illuminate\Contracts\Container\Container;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class BudgetPlanFactReleaseProductionCompositionTest extends TestCase
{
    public function test_selects_budget_plan_fact_profile_resolver_and_binding_from_the_production_registry_factory(): void
    {
        $resolver = new BudgetPlanFactReleaseCandidateResolver;
        $bindingFactory = new BudgetPlanFactReportBindingFactory(
            (new ReflectionClass(PlanFactReportSourceSnapshotAdapter::class))->newInstanceWithoutConstructor(),
            new BudgetPlanFactCandidateContract,
        );
        $procurementResolver = new ProcurementCycleReleaseCandidateResolver;
        $procurementBindings = (new ReflectionClass(ProcurementCycleReportBindingFactory::class))->newInstanceWithoutConstructor();
        $container = $this->createStub(Container::class);
        $container->method('make')->willReturnCallback(static function (string $abstract) use ($resolver, $bindingFactory, $procurementResolver, $procurementBindings): mixed {
            return match ($abstract) {
                BudgetPlanFactReleaseCandidateResolver::class => $resolver,
                BudgetPlanFactReportBindingFactory::class => $bindingFactory,
                ProcurementCycleReleaseCandidateResolver::class => $procurementResolver,
                ProcurementCycleReportBindingFactory::class => $procurementBindings,
                default => throw new \LogicException('unexpected_production_composition_dependency'),
            };
        });

        $dispatch = (new ProjectReportPublicationReleaseRequestRegistryFactory)
            ->dispatches($container)
            ->forCode(BudgetPlanFactCandidateContract::CODE);

        self::assertSame(BudgetPlanFactCandidateContract::CODE, $dispatch->profile->code);
        self::assertSame(BudgetPlanFactReleaseCandidateLayout::REQUEST_ID, $dispatch->profile->requestId);
        self::assertSame(BudgetPlanFactReleaseCandidateLayout::artifactPaths(), $dispatch->profile->artifactPaths);
        self::assertInstanceOf(BudgetPlanFactReleaseCandidateResolverAdapter::class, $dispatch->candidateResolver);
        self::assertInstanceOf(BudgetPlanFactReleaseBindingFactoryAdapter::class, $dispatch->bindings);
    }
}
