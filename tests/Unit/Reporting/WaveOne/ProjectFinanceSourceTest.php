<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\WaveOne;

use App\BusinessModules\Features\Budgeting\Reporting\ProjectFinance\DTO\ProjectMarginReportResult;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectFinance\DTO\WipForecastReportResult;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectFinance\ProjectControlMetricContract;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProjectFinanceSourceTest extends TestCase
{
    #[Test]
    public function project_control_contract_pins_the_shared_metric_version_and_codes(): void
    {
        $contract = new ProjectControlMetricContract();

        self::assertSame('project_control_core.v1', $contract->version());
        $contract->assertCompatible('project_control_core.v1', [
            'bac',
            'pv',
            'ev',
            'ac',
            'spi',
            'cpi',
            'eac',
        ]);

        $this->expectException(InvalidArgumentException::class);

        $contract->assertCompatible('project_control_core.v1', [
            'bac',
            'pv',
            'ev',
            'ac',
            'spi',
            'cpi',
            'eac',
            'eac',
        ]);
    }

    #[Test]
    public function typed_margin_result_preserves_the_legacy_wire_contract(): void
    {
        $payload = [
            'filters' => ['currency' => 'RUB'],
            'period' => ['from' => '2026-01-01', 'to' => '2026-01-31'],
            'summary' => ['margin' => 400],
            'totals_by_currency' => ['RUB' => ['margin' => 400]],
            'rows' => [['row_key' => 'margin-row']],
            'groups' => ['project'],
            'drill_down_available' => true,
            'sources_coverage' => [['source' => 'budget']],
            'warnings' => [],
            'meta' => ['formula_version' => 'budgeting.project-margin.v1'],
        ];

        self::assertSame($payload, ProjectMarginReportResult::fromArray($payload)->toArray());
    }

    #[Test]
    public function typed_wip_result_preserves_sensitive_and_audit_sections(): void
    {
        $payload = [
            'filters' => ['currency' => 'USD'],
            'period' => ['from' => '2026-01-01', 'to' => '2026-01-31'],
            'summary' => ['eac' => 110000],
            'totals_by_currency' => ['USD' => ['eac' => 110000]],
            'rows' => [['row_key' => 'wip-row']],
            'formulas' => [['key' => 'eac']],
            'assumptions' => [['key' => 'bottom_up_etc']],
            'source_coverage' => [['source' => 'earned_value']],
            'freshness' => ['status' => 'fresh'],
            'problem_flags' => [],
            'risk_flags' => [],
            'drill_down' => ['available' => true],
            'actions' => [],
            'meta' => [
                'formula_version' => 'budgeting.wip-forecast.v1',
                'audit_source_refs' => [['type' => 'audit_event', 'id' => 10]],
            ],
        ];

        self::assertSame($payload, WipForecastReportResult::fromArray($payload)->toArray());
    }
}
