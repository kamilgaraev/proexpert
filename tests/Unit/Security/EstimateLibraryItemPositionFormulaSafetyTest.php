<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Models\EstimateLibraryItemPosition;
use PHPUnit\Framework\TestCase;

final class EstimateLibraryItemPositionFormulaSafetyTest extends TestCase
{
    public function test_calculate_quantity_evaluates_arithmetic_formula_with_parameters(): void
    {
        $position = new EstimateLibraryItemPosition([
            'quantity_formula' => '({length} + 2) * 3',
            'default_quantity' => '1.0000',
            'coefficient' => '1.5000',
        ]);

        self::assertSame(27.0, $position->calculateQuantity(['length' => 4]));
    }

    public function test_calculate_quantity_rejects_php_statements(): void
    {
        $position = new EstimateLibraryItemPosition([
            'quantity_formula' => '1; file_put_contents("formula-executed", "1")',
            'default_quantity' => '7.0000',
            'coefficient' => '1.0000',
        ]);

        self::assertSame(7.0, $position->calculateQuantity());
        self::assertFileDoesNotExist(__DIR__.'/../../../formula-executed');
    }

    public function test_calculate_quantity_rejects_unresolved_parameters(): void
    {
        $position = new EstimateLibraryItemPosition([
            'quantity_formula' => '{missing} + 2',
            'default_quantity' => '5.0000',
            'coefficient' => '1.0000',
        ]);

        self::assertSame(5.0, $position->calculateQuantity());
    }

    public function test_calculate_quantity_rejects_division_by_zero(): void
    {
        $position = new EstimateLibraryItemPosition([
            'quantity_formula' => '10 / (3 - 3)',
            'default_quantity' => '2.0000',
            'coefficient' => '1.0000',
        ]);

        self::assertSame(2.0, $position->calculateQuantity());
    }
}
