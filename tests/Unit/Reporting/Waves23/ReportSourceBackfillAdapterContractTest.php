<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSourceBackfill;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSourceReadinessProbe;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\Backfill\ProjectControlCoreBackfill;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\Readiness\ProjectControlReadinessProbe;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Backfill\LookaheadReadinessBackfill;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Readiness\LookaheadReadinessProbe;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Readiness\BaselineScheduleVarianceReadinessProbe;
use App\BusinessModules\Features\ScheduleManagement\Reporting\ScheduleBaselineVersionBackfill;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Backfill\AcceptedProductionBackfill;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Readiness\AcceptedProductionReadinessProbe;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ReportSourceBackfillAdapterContractTest extends TestCase
{
    #[Test]
    public function every_r05_r08_adapter_exposes_exact_source_and_schema_contract(): void
    {
        $contracts = [
            ProjectControlCoreBackfill::class => ['project_evm_control', 'project_control_v1'],
            ScheduleBaselineVersionBackfill::class => ['baseline_schedule_variance', 'schedule_baseline_v1'],
            LookaheadReadinessBackfill::class => ['lookahead_readiness', 'lookahead_events_v1'],
            AcceptedProductionBackfill::class => [
                'accepted_production_progress',
                'production_acceptance_events_v1',
            ],
        ];

        foreach ($contracts as $class => [$sourceCode, $schemaVersion]) {
            $reflection = new ReflectionClass($class);
            self::assertTrue($reflection->implementsInterface(ReportSourceBackfill::class));
            $adapter = $reflection->newInstanceWithoutConstructor();
            self::assertSame($sourceCode, $adapter->sourceCode());
            self::assertSame($schemaVersion, $adapter->sourceSchemaVersion());
            $methods = array_values(array_intersect(
                array_map(static fn ($method): string => $method->name, $reflection->getMethods()),
                ['sourceCode', 'sourceSchemaVersion', 'nextBatch', 'apply'],
            ));
            sort($methods, SORT_STRING);
            self::assertSame(['apply', 'nextBatch', 'sourceCode', 'sourceSchemaVersion'], $methods);
        }
    }

    #[Test]
    public function every_r05_r08_probe_exposes_measurable_readiness_contract(): void
    {
        $contracts = [
            ProjectControlReadinessProbe::class => 'project_evm_control',
            BaselineScheduleVarianceReadinessProbe::class => 'baseline_schedule_variance',
            LookaheadReadinessProbe::class => 'lookahead_readiness',
            AcceptedProductionReadinessProbe::class => 'accepted_production_progress',
        ];

        foreach ($contracts as $class => $reportCode) {
            $reflection = new ReflectionClass($class);
            self::assertTrue($reflection->implementsInterface(ReportSourceReadinessProbe::class));
            $probe = $reflection->newInstanceWithoutConstructor();
            self::assertSame([$reportCode], $probe->reportCodes());
            self::assertTrue($reflection->hasMethod('inspect'));
        }
    }
}
