<?php

declare(strict_types=1);

namespace Tests\Unit\BusinessModules\Features\BudgetEstimates\Import;

use App\BusinessModules\Features\BudgetEstimates\Services\Import\Pdf\PdfEstimateTableNormalizer;
use Tests\TestCase;

final class PdfEstimateTableNormalizerTest extends TestCase
{
    public function test_it_extracts_rows_with_grouped_money_values(): void
    {
        $rows = (new PdfEstimateTableNormalizer())->normalize(
            "1 Монтаж оборудования шт 2 50 168,94 100 337,88\n"
        );

        self::assertCount(1, $rows);
        self::assertSame('Монтаж оборудования', $rows[0]->itemName);
        self::assertSame('шт', $rows[0]->unit);
        self::assertSame(2.0, $rows[0]->quantity);
        self::assertSame(50168.94, $rows[0]->unitPrice);
        self::assertSame(100337.88, $rows[0]->currentTotalAmount);
    }
}
