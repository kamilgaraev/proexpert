<?php

declare(strict_types=1);

namespace Tests\Unit\Contract;

use App\Repositories\Interfaces\ContractStateEventRepositoryInterface;
use App\Services\Contract\ContractStateCalculatorService;
use Mockery;
use PHPUnit\Framework\TestCase;

class ContractFinancialProjectionTest extends TestCase
{
    private ContractStateCalculatorService $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new ContractStateCalculatorService(
            Mockery::mock(ContractStateEventRepositoryInterface::class)
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_payment_event_does_not_change_contract_price(): void
    {
        $state = $this->calculator->calculate([
            ['event_type' => 'created', 'amount_delta' => '1000.00'],
            ['event_type' => 'payment_created', 'amount_delta' => '250.00'],
        ]);

        self::assertSame('1000.00', $state->totalAmount);
    }

    public function test_legacy_price_events_are_normalized_to_the_canonical_projection(): void
    {
        $state = $this->calculator->calculate([
            ['event_type' => 'created', 'amount_delta' => '1000.00'],
            ['event_type' => 'amended', 'amount_delta' => '100.00'],
            ['event_type' => 'supplementary_agreement_created', 'amount_delta' => '50.00'],
        ]);

        self::assertSame('1150.00', $state->totalAmount);
    }
}
