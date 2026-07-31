<?php

declare(strict_types=1);

namespace Tests\Unit\Budgeting;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDataProvider;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDrillDownProvider;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportRowQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Features\Budgeting\DTOs\BudgetingReportSourceClose;
use App\BusinessModules\Features\Budgeting\DTOs\BudgetingReportSourceCloseIdentity;
use App\BusinessModules\Features\Budgeting\DTOs\BudgetingReportSourceWatermark;
use App\BusinessModules\Features\Budgeting\Enums\BudgetingReportSourceCloseStatus;
use App\BusinessModules\Features\Budgeting\Services\ProjectMarginSourceSnapshotMaterializer;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class ProjectMarginSourceSnapshotMaterializerTest extends TestCase
{
    public function test_materializes_redacted_rows_with_stable_cursor_identity(): void
    {
        $write = $this->materialize($this->report());

        self::assertCount(2, $write->rows);
        self::assertMatchesRegularExpression('/^margin:[a-f0-9]{64}$/', $write->rows[0]->rowKey);
        self::assertNotSame($write->rows[0]->rowKey, $write->rows[1]->rowKey);
        self::assertSame([1, 2], array_map(static fn ($row): int => $row->ordinal, $write->rows));
        self::assertSame('attributions', $write->rows[0]->payload['drill']['column_id']);
        self::assertArrayNotHasKey('project', $write->rows[0]->payload);
        self::assertStringNotContainsString('Sensitive project', json_encode($write->rows, JSON_THROW_ON_ERROR));
        self::assertSame(2, $write->header->drillRowCount);
        self::assertSame('01JZZZZZZZZZZZZZZZZZZZZZZZ', $write->header->watermarks['close_id']);
        self::assertSame('margin-v1', $write->header->watermarks['formula_version']);
        self::assertSame('actuals:1', $write->header->watermarks['source_watermarks'][0]['watermark']);
        self::assertArrayNotHasKey('source_document_number', $write->drillRows[0]->payload);
        self::assertArrayNotHasKey('source_id', $write->drillRows[0]->payload);
        self::assertArrayNotHasKey('drill_down', $write->drillRows[0]->payload);
    }

    public function test_source_hash_and_row_order_do_not_depend_on_live_service_row_order(): void
    {
        $forward = $this->materialize($this->report());
        $reversed = $this->report();
        $reversed['rows'] = array_reverse($reversed['rows']);
        $backward = $this->materialize($reversed);

        self::assertSame($forward->header->sourceHash->value, $backward->header->sourceHash->value);
        self::assertSame($forward->header->snapshotHash->value, $backward->header->snapshotHash->value);
        self::assertSame(
            array_map(static fn ($row): string => $row->rowKey, $forward->rows),
            array_map(static fn ($row): string => $row->rowKey, $backward->rows),
        );
    }

    public function test_source_hash_changes_when_the_validated_close_identity_changes(): void
    {
        $first = $this->materialize($this->report());
        $second = (new ProjectMarginSourceSnapshotMaterializer())->materialize(
            '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            new ReportScope(1, [1], [10, 20], [], new DateTimeZone('UTC')),
            [
                'organization_id' => 1,
                'period_start' => '2026-01-01',
                'period_end' => '2026-01-31',
                'budget_version_uuid' => 'budget-1',
                'scenario_uuid' => 'scenario-1',
                'group_by' => ['month', 'project', 'currency'],
            ],
            $this->report(),
            ['first-key' => [$this->drill('line-1')], 'second-key' => [$this->drill('line-2')]],
            new DateTimeImmutable('2026-07-31T10:00:00+00:00'),
            new DateTimeImmutable('2026-07-31T10:05:00+00:00'),
            $this->close('01K00000000000000000000000'),
        );

        self::assertNotSame($first->header->sourceHash->value, $second->header->sourceHash->value);
        self::assertNotSame($first->header->snapshotHash->value, $second->header->snapshotHash->value);
    }

    public function test_materializer_is_not_a_runtime_provider_contract(): void
    {
        self::assertFalse(is_a(ProjectMarginSourceSnapshotMaterializer::class, ReportDataProvider::class, true));
        self::assertFalse(is_a(ProjectMarginSourceSnapshotMaterializer::class, ReportRowQuery::class, true));
        self::assertFalse(is_a(ProjectMarginSourceSnapshotMaterializer::class, ReportDrillDownProvider::class, true));
    }

    private function materialize(array $report): \App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotWrite
    {
        return (new ProjectMarginSourceSnapshotMaterializer())->materialize(
            '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            new ReportScope(1, [1], [10, 20], [], new DateTimeZone('UTC')),
            [
                'organization_id' => 1,
                'period_start' => '2026-01-01',
                'period_end' => '2026-01-31',
                'budget_version_uuid' => 'budget-1',
                'scenario_uuid' => 'scenario-1',
                'group_by' => ['month', 'project', 'currency'],
            ],
            $report,
            [
                'first-key' => [$this->drill('line-1')],
                'second-key' => [$this->drill('line-2')],
            ],
            new DateTimeImmutable('2026-07-31T10:00:00+00:00'),
            new DateTimeImmutable('2026-07-31T10:05:00+00:00'),
            $this->close(),
        );
    }

    private function close(string $closeId = '01JZZZZZZZZZZZZZZZZZZZZZZZ'): BudgetingReportSourceClose
    {
        return new BudgetingReportSourceClose(
            closeId: $closeId,
            identity: new BudgetingReportSourceCloseIdentity(1, '2026-01-01', '2026-01-31', 'scenario-1', 'budget-1'),
            sourceWatermarks: [
                new BudgetingReportSourceWatermark('actuals', new DateTimeImmutable('2026-01-31T17:00:00+00:00'), 'actuals:1', 'actuals-v1'),
                new BudgetingReportSourceWatermark('budget', new DateTimeImmutable('2026-01-31T17:00:00+00:00'), 'budget:1', 'budget-v1'),
            ],
            formulaVersion: 'margin-v1',
            sourceManifest: ['actuals' => ['version' => 'actuals:1'], 'budget' => ['version' => 'budget:1']],
            contentHash: str_repeat('a', 64),
            approvedBy: 1,
            approvedAt: new DateTimeImmutable('2026-02-01T00:00:00+00:00'),
            retainedUntil: new DateTimeImmutable('2033-01-31T00:00:00+00:00'),
            status: BudgetingReportSourceCloseStatus::APPROVED,
            restatesCloseId: null,
        );
    }

    private function report(): array
    {
        return [
            'filters' => ['budget_version_uuid' => 'budget-1', 'scenario_uuid' => 'scenario-1'],
            'period' => ['from' => '2026-01-01', 'to' => '2026-01-31'],
            'rows' => [
                $this->row('second-key', 20, 'Sensitive project B', 200.0),
                $this->row('first-key', 10, 'Sensitive project A', 100.0),
            ],
        ];
    }

    private function row(string $drillKey, int $projectId, string $projectName, float $revenue): array
    {
        $block = ['revenue' => $revenue, 'cost' => 20.0, 'gross_margin' => $revenue - 20.0, 'margin_percent' => 80.0];

        return [
            'group' => ['month' => '2026-01', 'project' => $projectId, 'currency' => 'RUB'],
            'currency' => 'RUB',
            'plan' => $block,
            'forecast' => $block,
            'actual' => $block,
            'variance' => $block,
            'source_types' => ['time_entry'],
            'problem_flags' => [],
            'risk_flags' => [],
            'quality_status' => 'actual',
            'source_rows_count' => 1,
            'drill_down_key' => $drillKey,
            'project' => ['id' => $projectId, 'name' => $projectName],
        ];
    }

    private function drill(string $lineId): array
    {
        return [
            'line_id' => $lineId,
            'component' => 'actual',
            'direction' => 'cost',
            'period' => '2026-01',
            'recognition_date' => '2026-01-15',
            'recognition_event' => 'management_cost_recognition',
            'attribution_rule' => 'approved_hours_by_hourly_rate',
            'currency' => 'RUB',
            'amount_without_vat' => 10.0,
            'vat_amount' => 0.0,
            'management_amount' => 10.0,
            'source_type' => 'time_entry',
            'quality_status' => 'actual',
            'confirmation_status' => 'confirmed',
            'freshness_status' => 'actual',
            'reconciliation_status' => 'actual',
            'problem_flags' => [],
            'risk_flags' => [],
            'source_document_number' => 'secret-42',
            'source_id' => 42,
            'drill_down' => ['href' => '/secret'],
        ];
    }
}
