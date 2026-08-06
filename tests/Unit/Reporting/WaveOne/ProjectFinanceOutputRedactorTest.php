<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\WaveOne;

use App\BusinessModules\Features\Budgeting\Reporting\ProjectFinance\ProjectFinanceOutputRedactor;
use PHPUnit\Framework\TestCase;

final class ProjectFinanceOutputRedactorTest extends TestCase
{
    private const SENSITIVE_FIELDS = [
        'bac_minor',
        'pv_minor',
        'ev_minor',
        'ac_minor',
        'ctc_minor',
        'eac_minor',
        'forecast_variance_minor',
    ];

    public function test_wip_totals_hide_sensitive_fields_without_sensitive_visibility(): void
    {
        $totals = $this->totals();

        $visible = (new ProjectFinanceOutputRedactor)->totals(
            'wip_completion_forecast',
            $totals,
            false,
        );

        self::assertSame(['RUB' => ['wip_minor' => 300]], $visible);
    }

    public function test_wip_totals_remain_complete_with_sensitive_visibility(): void
    {
        $totals = $this->totals();

        self::assertSame(
            $totals,
            (new ProjectFinanceOutputRedactor)->totals(
                'wip_completion_forecast',
                $totals,
                true,
            ),
        );
    }

    private function totals(): array
    {
        return [
            'RUB' => [
                'wip_minor' => 300,
                ...array_fill_keys(self::SENSITIVE_FIELDS, 100),
            ],
        ];
    }
}
