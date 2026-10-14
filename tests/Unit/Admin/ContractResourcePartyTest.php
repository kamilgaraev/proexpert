<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use App\Http\Resources\Api\V1\Admin\Contract\ContractResource;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\Organization;
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
            ['general_contract'],
            ['contract'],
            ['general_contractor_supply'],
            ['subcontract'],
            ['contractor_supply'],
            ['subcontractor_supply'],
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

    #[DataProvider('organizationRegistrationNumbers')]
    public function test_it_separates_organization_kpp_and_ogrn(?string $registrationNumber, ?string $kpp, ?string $ogrn): void
    {
        $contract = $this->contract([]);
        $organization = new Organization;
        $organization->setRawAttributes(['id' => 38, 'name' => 'Учебная организация', 'tax_number' => '6658522310', 'registration_number' => $registrationNumber]);
        $contract->setRelation('organization', $organization);

        $payload = (new ContractResource($contract))->resolve(Request::create('/'));

        self::assertSame('6658522310', $payload['organization_details']['inn']);
        self::assertSame($kpp, $payload['organization_details']['kpp']);
        self::assertArrayHasKey('ogrn', $payload['organization_details']);
        self::assertSame($ogrn, $payload['organization_details']['ogrn']);
        self::assertSame($registrationNumber, $organization->registration_number);
    }

    public static function organizationRegistrationNumbers(): array
    {
        return [
            ['1196658003277', null, '1196658003277'],
            ['325169000191393', null, '325169000191393'],
            ['770201001', '770201001', null],
            ['007 201 001', '007201001', null],
            ['ОГРН 119 665 800 3277', null, '1196658003277'],
            ['1234567890', null, null],
            ['', null, null],
            [null, null, null],
        ];
    }

    public function test_it_keeps_the_contractor_dedicated_kpp_separate_from_organization_registration(): void
    {
        $contract = $this->contract(['contractor_id' => 251]);
        $contractor = new Contractor;
        $contractor->setRawAttributes(['id' => 251, 'name' => 'Учебный подрядчик', 'inn' => '7702000002', 'kpp' => '770201001']);
        $contract->setRelation('contractor', $contractor);

        $payload = (new ContractResource($contract))->resolve(Request::create('/'));

        self::assertSame('770201001', $payload['contractor_details']['kpp']);
        self::assertArrayNotHasKey('organization_details', $payload);
        self::assertFalse($contract->relationLoaded('organization'));
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
