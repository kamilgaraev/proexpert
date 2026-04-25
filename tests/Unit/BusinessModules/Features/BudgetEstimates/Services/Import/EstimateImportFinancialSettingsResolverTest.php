<?php

declare(strict_types=1);

namespace Tests\Unit\BusinessModules\Features\BudgetEstimates\Services\Import;

use App\BusinessModules\Features\BudgetEstimates\Services\Import\EstimateImportFinancialSettingsResolver;
use PHPUnit\Framework\TestCase;

class EstimateImportFinancialSettingsResolverTest extends TestCase
{
    public function test_plain_mode_keeps_import_without_financial_charges(): void
    {
        $resolver = new EstimateImportFinancialSettingsResolver();

        $this->assertSame(
            [
                'vat_rate' => 0.0,
                'overhead_rate' => 0.0,
                'profit_rate' => 0.0,
                'preserve_imported_totals' => true,
            ],
            $resolver->resolve([
                'financial_mode' => 'plain',
                'vat_rate' => 20,
                'overhead_rate' => 15,
                'profit_rate' => 12,
            ])
        );
    }

    public function test_custom_mode_uses_user_rates_and_recalculates_totals(): void
    {
        $resolver = new EstimateImportFinancialSettingsResolver();

        $this->assertSame(
            [
                'vat_rate' => 10.0,
                'overhead_rate' => 8.5,
                'profit_rate' => 6.0,
                'preserve_imported_totals' => false,
            ],
            $resolver->resolve([
                'financial_mode' => 'custom',
                'vat_rate' => 10,
                'overhead_rate' => 8.5,
                'profit_rate' => 6,
            ])
        );
    }
}
