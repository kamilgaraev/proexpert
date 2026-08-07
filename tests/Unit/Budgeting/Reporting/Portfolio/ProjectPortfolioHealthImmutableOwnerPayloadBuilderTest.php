<?php

declare(strict_types=1);

namespace Tests\Unit\Budgeting\Reporting\Portfolio;

use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\ProjectPortfolioHealthImmutableOwnerPayloadBuilder;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ProjectPortfolioHealthImmutableOwnerPayloadBuilderTest extends TestCase
{
    #[Test]
    public function it_aggregates_exact_minor_units_and_preserves_the_worst_plan_fact_risk(): void
    {
        $payload = (new ProjectPortfolioHealthImmutableOwnerPayloadBuilder)->build([
            'project_margin' => [$this->marginRow()],
            'wip_completion_forecast' => [
                $this->wipRow('wip-1', 4_000, 1_200, 8_000, 10_000, 'earned_value', 201),
                $this->wipRow('wip-2', 1_000, 300, 2_000, 2_500, 'actual_cost', 202),
            ],
            'budget_plan_fact' => [
                $this->planFactRow('plan-1', -250, 'medium', 301),
                $this->planFactRow('plan-2', 100, 'high', 302),
            ],
        ]);

        self::assertSame('100.50', $payload['project_margin']['rows'][0]['actual']['revenue']);
        self::assertSame('70.25', $payload['project_margin']['rows'][0]['actual']['cost']);
        self::assertSame('60.00', $payload['project_margin']['rows'][0]['forecast']['gross_margin']);
        self::assertSame('15.00', $payload['wip_completion_forecast']['rows'][0]['metrics']['wip_total']);
        self::assertSame('75.00', $payload['wip_completion_forecast']['rows'][0]['metrics']['ftc']);
        self::assertSame('125.00', $payload['wip_completion_forecast']['rows'][0]['metrics']['eac']);
        self::assertSame('60.00', $payload['wip_completion_forecast']['rows'][0]['metrics']['forecast_gross_margin']);
        self::assertSame('-1.50', $payload['budget_plan_fact']['rows'][0]['variance_amount']);
        self::assertSame('high', $payload['budget_plan_fact']['rows'][0]['risk_level']);
        self::assertCount(2, $payload['wip_completion_forecast']['rows'][0]['source_refs']);
    }

    #[Test]
    public function it_fails_closed_when_any_mandatory_owner_payload_is_empty(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ProjectPortfolioHealthImmutableOwnerPayloadBuilder)->build([
            'project_margin' => [],
            'wip_completion_forecast' => [],
            'budget_plan_fact' => [],
        ]);
    }

    #[Test]
    public function it_accepts_the_existing_persisted_owner_row_shape_without_drill_refs(): void
    {
        $margin = $this->marginRow();
        $margin['source_refs'] = [];
        $wip = $this->wipRow('wip-1', 4_000, 1_200, 8_000, 10_000, 'earned_value', 201);
        $wip['source_refs'] = [];
        $planFact = $this->planFactRow('plan-1', -250, 'medium', 301);
        $planFact['source_refs'] = [];

        $payload = (new ProjectPortfolioHealthImmutableOwnerPayloadBuilder)->build([
            'project_margin' => [$margin],
            'wip_completion_forecast' => [$wip],
            'budget_plan_fact' => [$planFact],
        ]);

        self::assertSame([], $payload['project_margin']['rows'][0]['source_refs']);
        self::assertSame([], $payload['wip_completion_forecast']['rows'][0]['source_refs']);
        self::assertSame([], $payload['budget_plan_fact']['rows'][0]['source_refs']);
    }

    private function marginRow(): array
    {
        return [
            ...$this->identity('margin-1'),
            'actual_revenue_minor' => 10_050,
            'actual_cost_minor' => 7_025,
            'forecast_revenue_minor' => 15_000,
            'forecast_cost_minor' => 9_000,
            'source_refs' => [['type' => 'approved_act', 'id' => 101]],
        ];
    }

    private function wipRow(
        string $rowKey,
        int $ac,
        int $wip,
        int $ctc,
        int $eac,
        string $sourceType,
        int $sourceId,
    ): array {
        return [
            ...$this->identity($rowKey),
            'ac_minor' => $ac,
            'wip_minor' => $wip,
            'ctc_minor' => $ctc,
            'eac_minor' => $eac,
            'source_refs' => [['type' => $sourceType, 'id' => $sourceId]],
        ];
    }

    private function planFactRow(string $rowKey, int $variance, string $risk, int $sourceId): array
    {
        return [
            ...$this->identity($rowKey),
            'variance_minor' => $variance,
            'risk' => $risk,
            'source_refs' => [['type' => 'budget_line', 'id' => $sourceId]],
        ];
    }

    private function identity(string $rowKey): array
    {
        return [
            'row_key' => $rowKey,
            'project_id' => 10,
            'project_name' => 'Проект 10',
            'currency' => 'RUB',
        ];
    }
}
