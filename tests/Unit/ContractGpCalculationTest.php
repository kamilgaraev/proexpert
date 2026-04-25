<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\Contract\GpCalculationTypeEnum;
use App\Models\Contract;
use PHPUnit\Framework\TestCase;

class ContractGpCalculationTest extends TestCase
{
    public function test_general_contracting_percentage_is_included_in_entered_contract_amount(): void
    {
        $contract = new Contract([
            'is_fixed_amount' => true,
            'base_amount' => 100,
            'total_amount' => 100,
            'gp_calculation_type' => GpCalculationTypeEnum::PERCENTAGE,
            'gp_percentage' => 10,
        ]);

        $this->assertSame(10.0, $contract->gp_amount);
        $this->assertSame(100.0, $contract->total_amount_with_gp);
    }
}
