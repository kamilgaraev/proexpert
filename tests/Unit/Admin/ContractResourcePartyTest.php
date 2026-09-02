<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use App\Http\Resources\Api\V1\Admin\Contract\ContractResource;
use App\Models\Contract;
use App\Models\Supplier;
use App\Services\Contract\ContractSideResolverService;
use Illuminate\Http\Request;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ContractResourcePartyTest extends TestCase
{
    public function refreshDatabase(): void {}

    protected function setUp(): void
    {
        parent::setUp();

        $resolver = Mockery::mock(ContractSideResolverService::class);
        $resolver->shouldReceive('resolveCustomerAlias')->andReturn(null);
        $resolver->shouldReceive('resolve')->andReturn([]);
        $this->app->instance(ContractSideResolverService::class, $resolver);
    }

    #[DataProvider('contractSideTypes')]
    public function test_it_preserves_the_saved_side_type_for_editing(?string $sideType): void
    {
        $contract = $this->contract(['contract_side_type' => $sideType]);

        $payload = (new ContractResource($contract))->resolve(Request::create('/'));

        self::assertArrayHasKey('contract_side_type', $payload);
        self::assertSame($sideType, $payload['contract_side_type']);
    }

    public static function contractSideTypes(): array
    {
        return [
            ['customer_to_general_contractor'],
            ['general_contractor_to_contractor'],
            ['general_contractor_to_supplier'],
            ['contractor_to_subcontractor'],
            ['contractor_to_supplier'],
            ['subcontractor_to_supplier'],
            [null],
        ];
    }

    public function test_it_exposes_the_selected_supplier_without_unrelated_directory_fields(): void
    {
        $contract = $this->contract(['supplier_id' => 114]);
        $supplier = new Supplier;
        $supplier->setRawAttributes(['id' => 114, 'name' => 'Поставщик крепежа', 'email' => 'private@example.test']);
        $contract->setRelation('supplier', $supplier);

        $payload = (new ContractResource($contract))->resolve(Request::create('/'));

        self::assertSame(114, $payload['supplier_id'] ?? null);
        self::assertSame(['id' => 114, 'name' => 'Поставщик крепежа'], $payload['supplier'] ?? null);
    }

    public function test_it_keeps_an_absent_supplier_null(): void
    {
        $contract = $this->contract(['supplier_id' => null]);
        $contract->setRelation('supplier', null);

        $payload = (new ContractResource($contract))->resolve(Request::create('/'));

        self::assertArrayHasKey('supplier_id', $payload);
        self::assertNull($payload['supplier_id']);
        self::assertArrayHasKey('supplier', $payload);
        self::assertNull($payload['supplier']);
    }

    public function test_it_does_not_query_an_unloaded_supplier_to_serialize_the_reference(): void
    {
        $contract = $this->contract(['supplier_id' => 114]);

        $payload = (new ContractResource($contract))->resolve(Request::create('/'));

        self::assertSame(114, $payload['supplier_id'] ?? null);
        self::assertArrayNotHasKey('supplier', $payload);
        self::assertFalse($contract->relationLoaded('supplier'));
    }

    private function contract(array $attributes): Contract
    {
        $contract = new class extends Contract
        {
            public function usesEventSourcing(): bool
            {
                return false;
            }
        };
        $contract->setRawAttributes([
            'id' => 270,
            'organization_id' => 38,
            'number' => 'TEST-SUPPLY-04',
            'status' => 'draft',
            'base_amount' => 2500,
            'total_amount' => 2500,
            'is_fixed_amount' => true,
            'created_at' => '2026-09-02 12:00:00',
            'updated_at' => '2026-09-02 12:00:00',
            ...$attributes,
        ], true);

        return $contract;
    }
}
