<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Readiness;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportRunCreationStore;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Readiness\ReportCandidateReadinessGate;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDataProvider;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDrillDownProvider;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportRowQuery;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSourceReadinessProbe;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportRunReadinessProbe;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBinding;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBindingMap;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceReadiness;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSourceReadinessStatus;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\IdempotencyKey;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\ReadinessGuardedReportRunStore;
use Carbon\CarbonImmutable;
use Closure;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\Reporting\ReportDefinitionBuilder;
use Tests\Support\Reporting\ReportExecutionContextBuilder;
use Tests\Support\Reporting\ReportRunBuilder;

final class ReadinessGuardedReportRunStoreTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\DataProvider('sourceAvailability')]
    public function test_direct_readiness_probe_runs_before_persistence(bool $available): void
    {
        $definition = (new ReportDefinitionBuilder)->code('inventory_risk')->payload();
        $context = (new ReportExecutionContextBuilder)->build();
        $query = new ReportQuery($definition, $context->scope, new ReportFilterSet([]), [], new CarbonImmutable('2026-08-09'), 'ru');
        $key = new IdempotencyKey('direct-readiness-guard');
        $probe = $this->createMock(ReportRunReadinessProbe::class);
        $inspection = $probe->expects(self::once())->method('assertReady')->with($context, $query);
        $delegate = $this->createMock(ReportRunCreationStore::class);
        $run = (new ReportRunBuilder)->queued();
        $delegate->expects(self::once())->method('createOrReuse')
            ->with($context, $query, null, $key, self::isInstanceOf(Closure::class))
            ->willReturnCallback(static function ($context, $query, $view, $key, Closure $beforeCreate) use ($run) {
                $beforeCreate();
                return $run;
            });
        if (! $available) {
            $inspection->willThrowException(ReportContractException::fromCode(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE));
            $this->expectException(ReportContractException::class);
        }
        $binding = new ReportDefinitionBinding(
            $definition->code, $definition->definitionHash, $definition->contractVersion,
            $this->createMock(ReportDataProvider::class), $this->createMock(ReportRowQuery::class),
            $this->createMock(ReportDrillDownProvider::class), $probe,
        );
        $store = new ReadinessGuardedReportRunStore($delegate, new ReportDefinitionBindingMap([$definition->code => $binding]), new ReportCandidateReadinessGate);

        $result = $store->createOrReuse($context, $query, null, $key);

        self::assertSame($run, $result);
    }

    public static function sourceAvailability(): array
    {
        return [[false], [true]];
    }

    #[Test]
    public function unavailable_source_is_rejected_before_a_run_is_persisted(): void
    {
        $definition = (new ReportDefinitionBuilder)->code('project_portfolio_health')->payload();
        $context = (new ReportExecutionContextBuilder)->build();
        $query = new ReportQuery(
            $definition,
            $context->scope,
            new ReportFilterSet([]),
            [],
            new CarbonImmutable('2026-08-09T00:00:00+00:00'),
            'ru',
        );
        $readiness = new ReportSourceReadiness(
            ReportSourceReadinessStatus::UNAVAILABLE,
            4,
            0,
            4,
            0,
            'portfolio:missing',
            str_repeat('a', 64),
            str_repeat('b', 64),
            new CarbonImmutable('2026-08-09T00:00:00+00:00'),
        );
        $probe = $this->createMock(ReportSourceReadinessProbe::class);
        $probe->expects(self::once())->method('inspect')->with($context, $query)->willReturn($readiness);
        $delegate = $this->createMock(ReportRunCreationStore::class);
        $delegate->expects(self::once())->method('createOrReuse')->willReturnCallback(static function ($context, $query, $view, $key, Closure $beforeCreate) {
            $beforeCreate();
        });
        $binding = new ReportDefinitionBinding(
            $definition->code,
            $definition->definitionHash,
            $definition->contractVersion,
            $this->createMock(ReportDataProvider::class),
            $this->createMock(ReportRowQuery::class),
            $this->createMock(ReportDrillDownProvider::class),
            $probe,
        );
        $store = new ReadinessGuardedReportRunStore(
            $delegate,
            new ReportDefinitionBindingMap([$definition->code => $binding]),
            new ReportCandidateReadinessGate,
        );

        $this->expectException(ReportContractException::class);
        $store->createOrReuse($context, $query, null, new IdempotencyKey('readiness-guard'));
    }
}
