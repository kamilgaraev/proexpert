<?php

declare(strict_types=1);

namespace Tests\Unit\CompletedWork;

use App\DTOs\CompletedWork\CompletedWorkDTO;
use App\Models\CompletedWork;
use App\Services\CompletedWork\CompletedWorkService;
use App\Services\RateCoefficient\RateCoefficientService;
use Mockery;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class CompletedWorkFinancialIdempotencyTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_saving_materials_does_not_reapply_coefficient_to_existing_money(): void
    {
        $reflection = new ReflectionClass(CompletedWorkService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $coefficient = Mockery::mock(RateCoefficientService::class);
        $coefficient->shouldReceive('calculateAdjustedValueDetailed')->andReturnUsing(
            static fn ($organization, $amount, ...$rest): array => ['final' => round($amount * 1.1, 2)],
        );
        $reflection->getProperty('rateCoefficientService')->setValue($service, $coefficient);
        $prepare = $reflection->getMethod('prepareFinancialData');
        $initial = $prepare->invoke($service, $this->dto(50, 500, null));
        $work = new CompletedWork;
        $work->setRawAttributes(array_replace($initial, [
            'quantity' => (string) $initial['quantity'],
            'completed_quantity' => (string) $initial['completed_quantity'],
            'price' => (string) $initial['price'],
            'total_amount' => (string) $initial['total_amount'],
            'additional_info' => json_encode($initial['additional_info'], JSON_THROW_ON_ERROR),
        ]));
        $edit = $this->dto($initial['price'], $initial['total_amount'], []);
        $changed = $reflection->getMethod('financialInputsChanged')->invoke($service, $work, $edit);

        $saved = $prepare->invoke($service, $edit, $changed);

        self::assertSame(550.0, $initial['total_amount']);
        self::assertSame(550.0, $saved['total_amount']);
        self::assertSame(55.0, $saved['price']);
    }

    public function test_calculation_keeps_the_base_separate_from_the_adjusted_amount(): void
    {
        $reflection = new ReflectionClass(CompletedWorkService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $coefficient = Mockery::mock(RateCoefficientService::class);
        $coefficient->shouldReceive('calculateAdjustedValueDetailed')->andReturn([
            'original' => 500.0, 'final' => 550.0,
            'applications' => [['id' => 4, 'value' => 1.1]],
        ]);
        $reflection->getProperty('rateCoefficientService')->setValue($service, $coefficient);

        $data = $reflection->getMethod('prepareFinancialData')->invoke($service, $this->dto(50, 500, null));

        self::assertSame(500.0, $data['additional_info']['financial_calculation']['base_total_amount']);
        self::assertSame(50.0, $data['additional_info']['financial_calculation']['base_unit_price']);
        self::assertSame(550.0, $data['additional_info']['financial_calculation']['adjusted_total_amount']);
        self::assertSame([['id' => 4, 'value' => 1.1]], $data['additional_info']['financial_calculation']['applications']);
    }

    public function test_quantity_edit_reuses_base_price_instead_of_applying_coefficient_twice(): void
    {
        $reflection = new ReflectionClass(CompletedWorkService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $coefficient = Mockery::mock(RateCoefficientService::class);
        $coefficient->shouldReceive('calculateAdjustedValueDetailed')->once()->withArgs(
            static fn ($organization, $amount, ...$rest): bool => $amount === 1000.0,
        )->andReturn(['final' => 1100.0, 'applications' => []]);
        $reflection->getProperty('rateCoefficientService')->setValue($service, $coefficient);
        $work = new CompletedWork;
        $work->setRawAttributes([
            'quantity' => '10.000', 'price' => '55.00', 'total_amount' => '550.00',
            'additional_info' => json_encode(['financial_calculation' => [
                'base_unit_price' => 50.0, 'base_total_amount' => 500.0,
                'adjusted_total_amount' => 550.0, 'applications' => [],
            ]], JSON_THROW_ON_ERROR),
        ]);

        $data = $reflection->getMethod('prepareUpdatedFinancialData')->invoke(
            $service, $work, $this->dto(55, null, null, 20),
        );

        self::assertSame(1100.0, $data['total_amount']);
        self::assertSame(55.0, $data['price']);
    }

    private function dto(?float $price, ?float $total, ?array $materials, float $quantity = 10): CompletedWorkDTO
    {
        return new CompletedWorkDTO(
            id: null, organization_id: 7, project_id: 11, schedule_task_id: null,
            estimate_item_id: null, journal_entry_id: null, work_origin_type: 'manual',
            planning_status: 'planned', contract_id: null, contractor_id: null,
            work_type_id: null, user_id: null, quantity: $quantity, completed_quantity: $quantity,
            price: $price, total_amount: $total, completion_date: '2026-09-20',
            notes: null, status: 'pending', additional_info: null, materials: $materials,
        );
    }
}
